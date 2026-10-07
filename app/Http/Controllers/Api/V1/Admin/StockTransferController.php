<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TransferStatus;
use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\StockTransferController as ManagementStockTransferController;
use App\Http\Requests\Admin\ListStockTransfersRequest;
use App\Http\Resources\Admin\StockTransferDetailResource;
use App\Http\Resources\Admin\StockTransferSummaryResource;
use App\Models\StockTransfer;
use App\Models\User;
use App\Repositories\Admin\StockTransferRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AD-14 — platform stock-transfer oversight (WS14).
 *
 * Legacy's admin transfer screen was a read-only directory plus a detail page
 * whose approve/reject/dispatch/receive actions were thin delegators to
 * `Management\StockTransferController`, while its Cancel button (wrongly)
 * posted to the management route. This workstream reproduces that shape with
 * the audit's fixes:
 *
 *  - All five transitions are admin-scoped routes that delegate to the
 *    management controller, so the stock ledger keeps its single writer and
 *    the status machine (`TransferStatus::canTransitionTo` via the model's
 *    `canBe*` guards) is enforced in exactly one place. The admin cancel is
 *    now an admin route (legacy bug #13).
 *  - The delegated call runs under a business-scoped copy of the admin's
 *    identity (see `delegate()`), so `approved_by` / `dispatched_by` /
 *    `received_by`, the stock movements' `performed_by` and the activity-log
 *    timeline all name the admin who acted — never the business owner.
 *  - The list is platform-wide with all eight `TransferStatus` cases and
 *    carries per-status counts; `q` matches the code or either location name.
 *  - The detail `actions` map is the admin audience's (state guard only);
 *    the management payload's map is keyed on management permission strings
 *    that do not gate this console.
 *
 * The directory reads live in App\Repositories\Admin\StockTransferRepository
 * behind App\Http\Requests\Admin\ListStockTransfersRequest, and the two
 * admin-shaped payloads in the Admin transfer resources; this controller
 * keeps the HTTP contract, the platform guard and the delegated transitions.
 *
 * Legacy logged `admin_transfers_viewed` / `admin_transfer_show_viewed`.
 */
class StockTransferController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly StockTransferRepository $repository,
    ) {}

    public function index(ListStockTransfersRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        Log::info('admin_transfers_viewed', ['user_id' => $request->user()?->id]);

        $filters = $request->validated();

        $transfers = $this->repository->paginate($filters);
        $byStatus = $this->repository->statusCounts();

        return $this->ok(
            [
                'transfers' => StockTransferSummaryResource::collection($transfers->getCollection())->resolve($request),
                'statuses' => collect(TransferStatus::cases())->map(fn (TransferStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ])->all(),
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

    public function show(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizePlatformAdmin();

        Log::info('admin_transfer_show_viewed', ['user_id' => $request->user()?->id, 'transfer_id' => $transfer->id]);

        return $this->ok(['transfer' => $this->detail($request, $transfer)]);
    }

    public function approve(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->delegateTransition($request, $transfer, 'approve');
    }

    public function reject(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->delegateTransition($request, $transfer, 'reject');
    }

    public function dispatch(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->delegateTransition($request, $transfer, 'dispatch');
    }

    public function receive(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->delegateTransition($request, $transfer, 'receive');
    }

    /**
     * The admin-scoped cancel the audit asked for (legacy posted its admin
     * Cancel button to the management route). Same single writer underneath.
     */
    public function cancel(Request $request, StockTransfer $transfer): JsonResponse
    {
        return $this->delegateTransition($request, $transfer, 'cancel');
    }

    /**
     * Run one transition through the management controller and re-read the
     * transfer so the response carries the admin-shaped detail. Validation
     * errors and state conflicts (409) pass through untouched — the delegated
     * controller owns those messages.
     */
    private function delegateTransition(Request $request, StockTransfer $transfer, string $action): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $response = $this->delegate($request, $transfer, $action);

        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        return $this->ok(
            ['transfer' => $this->detail($request, $transfer->fresh())],
            $response->getData(true)['message'] ?? null,
        );
    }

    /**
     * The transfer detail the admin screens render: the management payload
     * (items with requested/approved deltas, timeline, movements) widened
     * with the owning business and the admin audience's action flags.
     *
     * @return array<string, mixed>
     */
    private function detail(Request $request, StockTransfer $transfer): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->delegate($request, $transfer, 'show')->getData(true)['data']['transfer'] ?? [];

        $transfer->loadMissing('business:id,name,business_code');

        return (new StockTransferDetailResource($transfer, $payload))->resolve($request);
    }

    /**
     * Delegate one controller action to the management implementation.
     *
     * The management controller resolves its tenant from the authenticated
     * user's `business_id`, and a platform admin carries none — so the
     * delegated call runs under a business-scoped copy of the admin identity:
     * the copy keeps the admin's id (every `approved_by` / `dispatched_by` /
     * `received_by` stamp, stock-movement `performed_by` and activity row
     * still names the admin who acted) and only supplies the transfer's
     * business as tenant context. The copy exists for one controller call and
     * is never persisted; the real request user is restored afterwards so the
     * route-access logger still sees the admin.
     */
    private function delegate(Request $request, StockTransfer $transfer, string $action): JsonResponse
    {
        $admin = $request->user();

        if (! $admin instanceof User) {
            abort(403, 'This endpoint is restricted to platform administrators.');
        }

        $context = clone $admin;
        $context->setAttribute('business_id', $transfer->business_id);

        $original = $request->getUserResolver();
        $request->setUserResolver(fn () => $context);

        try {
            return app(ManagementStockTransferController::class)->{$action}($request, $transfer);
        } finally {
            $request->setUserResolver($original);
        }
    }
}
