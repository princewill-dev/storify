<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\TransferStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Transfer\ApproveStockTransferRequest;
use App\Http\Requests\Management\Transfer\IndexStockTransferRequest;
use App\Http\Requests\Management\Transfer\RejectStockTransferRequest;
use App\Http\Requests\Management\Transfer\SourceProductsRequest;
use App\Http\Requests\Management\Transfer\StockLocationsRequest;
use App\Http\Requests\Management\Transfer\StoreStockTransferRequest;
use App\Http\Resources\Management\Transfer\SourceProductsPayloadResource;
use App\Http\Resources\Management\Transfer\StockLocationsPayloadResource;
use App\Http\Resources\Management\Transfer\StockTransferDetailResource;
use App\Http\Resources\Management\Transfer\StockTransferSummaryResource;
use App\Http\Resources\Management\Transfer\TransferLocationsResource;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Repositories\Management\StockTransferRepository;
use App\Services\Access\TenantGuard;
use App\Services\Management\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WS-15 — the inventory transfer / "Stock Adjustment" workflow.
 *
 * Legacy exposed a ten-route state machine (create, submit, approve, reject,
 * acknowledge, dispatch, receive, cancel) against `Management\StockTransferController`.
 * The state machine, the per-line approved-quantity semantics and the
 * awaiting-acknowledgment loop are reproduced here; the audit-flagged defects
 * are deliberately not:
 *
 *  - Legacy let any business read any transfer by id (there was no business
 *    check on show/actions at all); every read and transition is scoped to
 *    the authenticated user's business and, for restricted staff, to their
 *    assigned locations.
 *  - Legacy's dispatch swallowed the per-line shortage detail into a generic
 *    "Failed to dispatch transfer." flash. The shortage is surfaced here,
 *    listing every short line in one 422.
 *  - Legacy's grid offered products whose stock lived on `Product.quantity`
 *    with no `StockLocation` row, then dispatch always failed for them. Both
 *    the grid and dispatch read the same two sources now, and dispatch seeds
 *    the missing source location from `Product.quantity` (the same convention
 *    POS uses on first sale) so the two cannot disagree.
 *  - Legacy synced `Product.quantity` asymmetrically (decrement only when the
 *    product was assigned to the source, always increment on receive), which
 *    inflated the global count for drifted products; the sync is symmetric.
 *
 * All transitions are state-guarded, run in a transaction and write an
 * `ActivityLog` row under the legacy `transfer.*` action names (which the
 * detail timeline reads back) plus a matching `Log::info` line.
 *
 * The workflows (transitions, the two-pass dispatch, the ledger calls and
 * their transactions) live in App\Services\Management\StockTransferService,
 * the reads and row-level locks in
 * App\Repositories\Management\StockTransferRepository, and validation and
 * payloads in the Transfer request/resource namespaces. This controller keeps
 * the HTTP contract and the authorization guards.
 */
class StockTransferController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly StockTransferRepository $repository,
        private readonly StockTransferService $service,
        private readonly TenantGuard $tenantGuard,
    ) {}

    public function index(IndexStockTransferRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $user = $this->user($request);

        $transfers = $this->repository->paginateForUser($user, $filters);
        $byStatus = $this->repository->statusCounts($user);

        return $this->ok(
            [
                'transfers' => StockTransferSummaryResource::collection($transfers->getCollection())->resolve($request),
                'statuses' => $this->statusOptions(),
                'stats' => [
                    'total' => array_sum($byStatus),
                    'by_status' => $byStatus,
                ],
            ],
            null,
            200,
            $this->paginationMeta($transfers),
        );
    }

    public function store(StoreStockTransferRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validated();

        [$from, $to] = $this->service->resolveLocations($user, $data);
        $items = $this->service->validatedItems($user, $data['items']);

        // `submitted` is the legacy flag; `status` is accepted as the same
        // choice expressed the way the new API names it. `submitted` wins when
        // both are sent.
        $submitted = $request->boolean('submitted', false);
        if (array_key_exists('status', $data) && $data['status'] !== null && ! $request->has('submitted')) {
            $submitted = $data['status'] === 'pending';
        }

        $transfer = $this->service->create($request, $user, $data, $from, $to, $items, $submitted);

        return $this->transferResponse(
            $request,
            $transfer,
            $submitted ? 'Transfer request submitted for approval.' : 'Transfer saved as draft.',
            201,
        );
    }

    public function show(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizeTransfer($request, $transfer);

        return $this->ok(['transfer' => $this->detail($request, $transfer)]);
    }

    public function submit(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizeTransfer($request, $transfer);

        if (! $transfer->canBeSubmitted()) {
            return $this->error('This transfer cannot be submitted.', 409);
        }

        $this->service->transition(
            $request,
            $transfer,
            $this->user($request),
            TransferStatus::PENDING,
            'transfer.submitted',
            'Transfer submitted for approval.',
        );

        return $this->transferResponse($request, $transfer, 'Transfer submitted for approval.');
    }

    public function cancel(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizeTransfer($request, $transfer);

        if (! $transfer->canBeCancelled()) {
            return $this->error('This transfer cannot be cancelled.', 409);
        }

        $this->service->transition(
            $request,
            $transfer,
            $this->user($request),
            TransferStatus::CANCELLED,
            'transfer.cancelled',
            'Transfer cancelled.',
        );

        return $this->transferResponse($request, $transfer, 'Transfer cancelled.');
    }

    public function approve(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizeTransfer($request, $transfer);

        if (! $transfer->canBeApproved()) {
            return $this->error('This transfer cannot be approved.', 409);
        }

        // Resolved from the container rather than type-hinted: the AD-14
        // admin console delegates this action with a plain Request. It runs
        // here, after the 403/409 guards, exactly where the pre-refactor
        // `$request->validate()` stood — see the request's docblock.
        $data = app(ApproveStockTransferRequest::class)->validated();

        $adjusted = $this->service->approve(
            $request,
            $transfer,
            $this->user($request),
            $data['approved_quantities'] ?? [],
        );

        $message = $adjusted
            ? 'Quantities adjusted and sent for acknowledgement.'
            : 'Transfer approved.';

        return $this->transferResponse($request, $transfer, $message);
    }

    public function reject(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizeTransfer($request, $transfer);

        if (! $transfer->canBeRejected()) {
            return $this->error('This transfer cannot be rejected.', 409);
        }

        // See approve(): container-resolved for the admin console's delegation.
        $data = app(RejectStockTransferRequest::class)->validated();

        $this->service->reject($request, $transfer, $this->user($request), $data['rejection_reason']);

        return $this->transferResponse($request, $transfer, 'Transfer rejected.');
    }

    public function acknowledge(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizeTransfer($request, $transfer);

        if (! $transfer->canBeAcknowledged()) {
            return $this->error('This transfer is not awaiting acknowledgement.', 409);
        }

        $this->service->transition(
            $request,
            $transfer,
            $this->user($request),
            TransferStatus::APPROVED,
            'transfer.acknowledged',
            'Approved quantities acknowledged',
        );

        return $this->transferResponse($request, $transfer, 'Quantities acknowledged. Transfer is now approved.');
    }

    public function dispatch(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizeTransfer($request, $transfer);
        $this->authorizeTransferSide($request, $transfer, 'from');

        if (! $transfer->canBeDispatched()) {
            return $this->error('This transfer cannot be dispatched.', 409);
        }

        // Loaded here, outside the try, exactly where the pre-refactor action
        // had them: a DB-layer failure during these reads must still surface
        // as a 500 rather than the 422 the workflow-exception mapping below
        // produces (which would echo the driver message). The service repeats
        // the same loadMissing, which is then a no-op.
        $transfer->loadMissing(['items.product', 'items.variant', 'toLocation']);

        try {
            $result = $this->service->dispatch($request, $transfer, $this->user($request));
        } catch (\RuntimeException|\DomainException $e) {
            Log::warning('transfer.dispatch_failed', [
                'transfer_id' => $transfer->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error($e->getMessage(), 422, ['items' => [$e->getMessage()]]);
        }

        if (isset($result['conflict'])) {
            return $this->error('This transfer cannot be dispatched.', 409);
        }

        if (isset($result['shortages'])) {
            Log::warning('transfer.dispatch_failed', [
                'transfer_id' => $transfer->id,
                'shortages' => $result['shortages'],
            ]);

            return $this->error(implode(' ', $result['shortages']), 422, ['items' => $result['shortages']]);
        }

        return $this->transferResponse($request, $transfer, 'Transfer dispatched. Stock moved from the source location.');
    }

    public function receive(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizeTransfer($request, $transfer);
        $this->authorizeTransferSide($request, $transfer, 'to');

        if (! $transfer->canBeReceived()) {
            return $this->error('This transfer cannot be received yet.', 409);
        }

        // Same placement as dispatch(): outside the try that maps workflow
        // exceptions to 422, so a DB-layer failure during these reads keeps
        // the pre-refactor 500. The service's loadMissing is a no-op after.
        $transfer->loadMissing(['items.product', 'items.variant', 'fromLocation']);

        try {
            $conflict = $this->service->receive($request, $transfer, $this->user($request));
        } catch (\RuntimeException|\DomainException $e) {
            Log::warning('transfer.receive_failed', [
                'transfer_id' => $transfer->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to receive transfer: '.$e->getMessage(), 422);
        }

        if ($conflict) {
            return $this->error('This transfer cannot be received yet.', 409);
        }

        return $this->transferResponse($request, $transfer, 'Transfer received. Stock added to the destination location.');
    }

    /**
     * Accessible warehouses and stores for the From/To pickers, with the
     * stock each holds. Restricted staff see only their assigned locations,
     * exactly like the legacy create form.
     */
    public function locations(Request $request): JsonResponse
    {
        $locations = $this->repository->accessibleLocations($this->user($request));

        return $this->ok(
            (new TransferLocationsResource($locations['warehouses'], $locations['stores']))->resolve($request),
        );
    }

    /**
     * The create screen's product grid: everything with available stock at
     * one source location. Reads `StockLocation` rows first and falls back to
     * `Product.quantity` for products assigned to the location that have no
     * stock-location row yet — the same two sources dispatch reads.
     */
    public function sourceProducts(SourceProductsRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $user = $this->user($request);

        $location = $this->service->resolveLocation($user, $filters['location_type'], (int) $filters['location_id'], 'location_id');
        $class = $location::class;

        $rows = $this->repository->stockLocationsAt($user, $class, $location->getKey(), $filters['q'] ?? null)
            ->with(['product.images', 'productVariant'])
            ->get();

        $covered = $this->repository->stockedProductIds($user, $class, $location->getKey());

        $fallback = $this->repository->sourceProductFallback($user, $class, $location->getKey(), $filters['q'] ?? null, $covered);

        return $this->ok((new SourceProductsPayloadResource($class, $location, $rows, $fallback))->resolve($request));
    }

    /**
     * The stock-location rows behind the create grid — the roadmap's
     * `stock-locations?source_type=&source_id=` read. `location_type` /
     * `location_id` are accepted as the same pair.
     */
    public function stockLocations(StockLocationsRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $type = $filters['location_type'] ?? $filters['source_type'] ?? null;
        $id = $filters['location_id'] ?? $filters['source_id'] ?? null;

        if (! $type || ! $id) {
            return $this->error('A location_type + location_id (or source_type + source_id) pair is required.', 422);
        }

        $user = $this->user($request);
        $location = $this->service->resolveLocation($user, $type, (int) $id, 'location_id');
        $class = $location::class;

        $rows = $this->repository->stockLocationsAt($user, $class, $location->getKey(), $filters['q'] ?? null)
            ->with(['product.images', 'productVariant'])
            ->get();

        return $this->ok((new StockLocationsPayloadResource($class, $location, $rows))->resolve($request));
    }

    /**
     * Every action's success response: re-read the transfer so the payload
     * reflects the committed state, shape it and wrap it in the envelope.
     */
    private function transferResponse(Request $request, StockTransfer $transfer, string $message, int $status = 200): JsonResponse
    {
        return $this->ok(['transfer' => $this->detail($request, $transfer->fresh())], $message, $status);
    }

    /**
     * The transfer detail payload. The repository owns the eager loads and the
     * reads (timeline activity rows, ledger movements, per-item source
     * availability); the resource turns them into the JSON shape.
     *
     * @return array<string, mixed>
     */
    private function detail(Request $request, StockTransfer $transfer): array
    {
        $transfer = $this->repository->loadDetail($transfer);

        // Read order matches the pre-refactor payload build: per-item source
        // availability first, then the timeline rows, then the movements.
        $availableAtSource = $this->repository->availabilityMap($transfer);

        return (new StockTransferDetailResource(
            $transfer,
            $this->user($request),
            $this->repository->activityLogs($transfer),
            $this->repository->movements($transfer),
            $availableAtSource,
        ))->resolve($request);
    }

    private function authorizeTransfer(Request $request, StockTransfer $transfer): void
    {
        $user = $this->user($request);

        // Legacy never checked the business on show or on any transition.
        $this->tenantGuard->authorizeBusiness($transfer, $user, 'You do not have access to this transfer.');

        if ($user->isRestrictedStaff()
            && ! $this->locationAccessible($user, $transfer->from_location_type, (int) $transfer->from_location_id)
            && ! $this->locationAccessible($user, $transfer->to_location_type, (int) $transfer->to_location_id)) {
            abort(403, 'You do not have access to this transfer.');
        }
    }

    /**
     * Dispatch drains the source and receive fills the destination, so a
     * restricted staff member must be assigned to that specific end.
     */
    private function authorizeTransferSide(Request $request, StockTransfer $transfer, string $side): void
    {
        $user = $this->user($request);

        if (! $user->isRestrictedStaff()) {
            return;
        }

        $type = $side === 'from' ? $transfer->from_location_type : $transfer->to_location_type;
        $id = $side === 'from' ? (int) $transfer->from_location_id : (int) $transfer->to_location_id;

        if (! $this->locationAccessible($user, $type, $id)) {
            abort(403, 'You do not have access to this transfer.');
        }
    }

    /**
     * Location access for restricted staff keeps the polymorphic type in
     * play: a store side checks accessible stores, a warehouse side accessible
     * warehouses. Deliberately not TenantGuard::authorizeStoreId, which is
     * the store-only shape.
     */
    private function locationAccessible(User $user, string $class, int $id): bool
    {
        return $class === Warehouse::class
            ? $user->accessibleWarehouses()->whereKey($id)->exists()
            : $user->accessibleStores()->whereKey($id)->exists();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return array_map(fn (TransferStatus $status) => [
            'value' => $status->value,
            'label' => $status->label(),
        ], TransferStatus::cases());
    }
}
