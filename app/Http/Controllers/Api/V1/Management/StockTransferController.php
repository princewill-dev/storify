<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\TransferStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Store;
use App\Models\Warehouse;
use App\Services\StockLedgerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
 */
class StockTransferController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(private readonly StockLedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in($this->statusValues())],
            'q' => ['nullable', 'string', 'max:100'],
            'location_type' => ['nullable', Rule::in(['warehouse', 'store'])],
            'location_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $this->listQuery($request)
            ->with(['fromLocation', 'toLocation', 'requester:id,name', 'approver:id,name', 'dispatcher:id,name', 'receiver:id,name'])
            ->withCount('items')
            ->withSum('items as total_units', 'quantity')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(fn ($inner) => $inner
                    ->where('transfer_code', 'like', "%{$term}%")
                    ->orWhereHas('requester', fn ($r) => $r->where('name', 'like', "%{$term}%")));
            });

        if (! empty($filters['location_type']) && ! empty($filters['location_id'])) {
            $class = $this->locationClass($filters['location_type']);
            $id = (int) $filters['location_id'];

            $query->where(function ($q) use ($class, $id) {
                $q->where(fn ($side) => $side->where('from_location_type', $class)->where('from_location_id', $id))
                    ->orWhere(fn ($side) => $side->where('to_location_type', $class)->where('to_location_id', $id));
            });
        }

        $transfers = $query->latest('id')->paginate($filters['per_page'] ?? 20)->withQueryString();

        $byStatus = $this->listQuery($request)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();

        return $this->ok(
            [
                'transfers' => $transfers->getCollection()->map(fn (StockTransfer $t) => $this->summary($t))->all(),
                'statuses' => collect(TransferStatus::cases())->map(fn (TransferStatus $s) => [
                    'value' => $s->value,
                    'label' => $s->label(),
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

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);

        if ($user->business_id === null) {
            abort(403, 'A business account is required to create stock transfers.');
        }

        $data = $request->validate([
            'from_location_type' => ['required', Rule::in(['warehouse', 'store'])],
            'from_location_id' => ['required', 'integer'],
            'to_location_type' => ['required', Rule::in(['warehouse', 'store'])],
            'to_location_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'submitted' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['draft', 'pending'])],
        ]);

        $from = $this->resolveLocation($request, $data['from_location_type'], (int) $data['from_location_id'], 'from_location_id');
        $to = $this->resolveLocation($request, $data['to_location_type'], (int) $data['to_location_id'], 'to_location_id');

        if ($from->is($to)) {
            throw ValidationException::withMessages([
                'to_location_id' => ['Source and destination cannot be the same location.'],
            ]);
        }

        $items = $this->validatedItems($request, $data['items']);

        // `submitted` is the legacy flag; `status` is accepted as the same
        // choice expressed the way the new API names it. `submitted` wins when
        // both are sent.
        $submitted = $request->boolean('submitted', false);
        if (array_key_exists('status', $data) && $data['status'] !== null && ! $request->has('submitted')) {
            $submitted = $data['status'] === 'pending';
        }

        $transfer = DB::transaction(function () use ($request, $user, $data, $from, $to, $items, $submitted) {
            $transfer = StockTransfer::create([
                'business_id' => $user->business_id,
                'from_location_type' => $from::class,
                'from_location_id' => $from->getKey(),
                'to_location_type' => $to::class,
                'to_location_id' => $to->getKey(),
                'requested_by' => $user->id,
                'status' => $submitted ? TransferStatus::PENDING : TransferStatus::DRAFT,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($items as $item) {
                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                    'quantity' => $item['quantity'],
                ]);
            }

            $this->logEvent($request, $transfer, 'transfer.created', 'Transfer created from '.$from->name.' to '.$to->name);

            return $transfer;
        });

        return $this->ok(
            ['transfer' => $this->detail($request, $transfer->fresh())],
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

        return $this->transition($request, $transfer, TransferStatus::PENDING, 'transfer.submitted', 'Transfer submitted for approval.', 'Transfer submitted for approval.');
    }

    public function cancel(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizeTransfer($request, $transfer);

        if (! $transfer->canBeCancelled()) {
            return $this->error('This transfer cannot be cancelled.', 409);
        }

        return $this->transition($request, $transfer, TransferStatus::CANCELLED, 'transfer.cancelled', 'Transfer cancelled.', 'Transfer cancelled.');
    }

    public function approve(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizeTransfer($request, $transfer);

        if (! $transfer->canBeApproved()) {
            return $this->error('This transfer cannot be approved.', 409);
        }

        $data = $request->validate([
            'approved_quantities' => ['sometimes', 'array'],
            'approved_quantities.*' => ['integer', 'min:1', 'max:1000000'],
        ]);

        $user = $this->user($request);

        $adjusted = DB::transaction(function () use ($request, $transfer, $user, $data) {
            $transfer->loadMissing('items');

            foreach ($transfer->items as $item) {
                // Legacy defaults every line to its requested quantity and
                // clamps any higher adjustment back down.
                $approved = (int) ($data['approved_quantities'][$item->id] ?? $item->quantity);
                $item->update(['approved_quantity' => min($approved, (int) $item->quantity)]);
            }

            $adjusted = $transfer->items()->whereColumn('approved_quantity', '<', 'quantity')->exists();

            // Adjusted lines must be acknowledged by the requesting side
            // before the transfer is truly approved; re-approving an
            // already-acknowledged transfer resolves straight to approved.
            $status = $transfer->isPending() && $adjusted
                ? TransferStatus::AWAITING_ACKNOWLEDGMENT
                : TransferStatus::APPROVED;

            $transfer->update(['status' => $status, 'approved_by' => $user->id]);

            $this->logEvent(
                $request,
                $transfer,
                'transfer.approved',
                $adjusted
                    ? 'Quantities adjusted and sent for acknowledgement'
                    : 'Transfer approved',
            );

            return $adjusted;
        });

        $message = $adjusted
            ? 'Quantities adjusted and sent for acknowledgement.'
            : 'Transfer approved.';

        return $this->ok(['transfer' => $this->detail($request, $transfer->fresh())], $message);
    }

    public function reject(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizeTransfer($request, $transfer);

        if (! $transfer->canBeRejected()) {
            return $this->error('This transfer cannot be rejected.', 409);
        }

        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ]);

        $user = $this->user($request);

        DB::transaction(function () use ($request, $transfer, $user, $data) {
            $transfer->update([
                'status' => TransferStatus::REJECTED,
                'rejection_reason' => $data['rejection_reason'],
                'approved_by' => $user->id,
            ]);

            $this->logEvent($request, $transfer, 'transfer.rejected', 'Transfer rejected: '.$data['rejection_reason']);
        });

        return $this->ok(['transfer' => $this->detail($request, $transfer->fresh())], 'Transfer rejected.');
    }

    public function acknowledge(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizeTransfer($request, $transfer);

        if (! $transfer->canBeAcknowledged()) {
            return $this->error('This transfer is not awaiting acknowledgement.', 409);
        }

        return $this->transition(
            $request,
            $transfer,
            TransferStatus::APPROVED,
            'transfer.acknowledged',
            'Quantities acknowledged. Transfer is now approved.',
            'Approved quantities acknowledged',
        );
    }

    public function dispatch(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizeTransfer($request, $transfer);
        $this->authorizeTransferSide($request, $transfer, 'from');

        if (! $transfer->canBeDispatched()) {
            return $this->error('This transfer cannot be dispatched.', 409);
        }

        $transfer->loadMissing(['items.product', 'items.variant', 'toLocation']);

        $user = $this->user($request);
        $destination = $transfer->toLocation?->name ?? 'destination';

        try {
            $result = DB::transaction(function () use ($request, $transfer, $user, $destination) {
                // Serialize concurrent transition attempts on the same transfer;
                // the route-bound instance was read before this lock existed.
                $locked = StockTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

                if (! $locked->canBeDispatched()) {
                    return ['conflict' => true];
                }

                // Pre-flight every line before touching stock, so the caller gets
                // every shortage in one response instead of legacy's generic
                // "Failed to dispatch transfer." flash.
                $shortages = [];

                foreach ($transfer->items as $item) {
                    $qty = (int) ($item->approved_quantity ?? $item->quantity);
                    $source = $this->sourceStock($transfer, $item);

                    // Same two sources the mutation pass reads: an existing
                    // stock-location row, else the product's assigned quantity
                    // (which the mutation pass will seed the location from).
                    $available = $source?->quantity
                        ?? $this->productAssignmentQuantity($transfer->from_location_type, (int) $transfer->from_location_id, $item)
                        ?? 0;

                    if ($available < $qty) {
                        $shortages[] = sprintf(
                            'Insufficient stock for "%s": %d available, %d requested.',
                            $item->product?->name ?? 'Unknown product',
                            (int) $available,
                            $qty,
                        );
                    }
                }

                if ($shortages !== []) {
                    return ['shortages' => $shortages];
                }

                foreach ($transfer->items as $item) {
                    $qty = (int) ($item->approved_quantity ?? $item->quantity);

                    // The pre-flight above proved this resolves; seed: true lets a
                    // product carrying its stock on Product.quantity (rather than a
                    // StockLocation row) still be dispatched.
                    $source = $this->sourceStock($transfer, $item, seed: true);

                    if (! $source) {
                        // Only reachable if another writer drained the line between
                        // the passes; throw so the transaction rolls back the lines
                        // already removed above.
                        throw new \RuntimeException(sprintf(
                            'Insufficient stock for "%s": 0 available, %d requested.',
                            $item->product?->name ?? 'Unknown product',
                            $qty,
                        ));
                    }

                    $this->ledger->recordRemoval(
                        $source,
                        $qty,
                        $transfer,
                        $user,
                        'Transfer dispatched to '.$destination,
                    );

                    $this->syncProductQuantity($item, $transfer, $qty, 'remove');
                }

                $transfer->update([
                    'status' => TransferStatus::DISPATCHED,
                    'dispatched_by' => $user->id,
                ]);

                $this->logEvent($request, $transfer, 'transfer.dispatched', 'Transfer dispatched to '.$destination);

                return ['ok' => true];
            });
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

        return $this->ok(
            ['transfer' => $this->detail($request, $transfer->fresh())],
            'Transfer dispatched. Stock moved from the source location.',
        );
    }

    public function receive(Request $request, StockTransfer $transfer): JsonResponse
    {
        $this->authorizeTransfer($request, $transfer);
        $this->authorizeTransferSide($request, $transfer, 'to');

        if (! $transfer->canBeReceived()) {
            return $this->error('This transfer cannot be received yet.', 409);
        }

        $transfer->loadMissing(['items.product', 'items.variant', 'fromLocation']);

        $user = $this->user($request);
        $source = $transfer->fromLocation?->name ?? 'source';

        try {
            $conflict = DB::transaction(function () use ($request, $transfer, $user, $source) {
                $locked = StockTransfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

                if (! $locked->canBeReceived()) {
                    return true;
                }

                foreach ($transfer->items as $item) {
                    $qty = (int) ($item->approved_quantity ?? $item->quantity);

                    $destination = $this->destinationStock($transfer, $item);

                    $this->ledger->recordAddition(
                        $destination,
                        $qty,
                        $transfer,
                        $user,
                        'Transfer received from '.$source,
                    );

                    $this->syncProductQuantity($item, $transfer, $qty, 'add');
                }

                $transfer->update([
                    'status' => TransferStatus::RECEIVED,
                    'received_by' => $user->id,
                ]);

                $this->logEvent($request, $transfer, 'transfer.received', 'Transfer received from '.$source);

                return false;
            });
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

        return $this->ok(
            ['transfer' => $this->detail($request, $transfer->fresh())],
            'Transfer received. Stock added to the destination location.',
        );
    }

    /**
     * Accessible warehouses and stores for the From/To pickers, with the
     * stock each holds. Restricted staff see only their assigned locations,
     * exactly like the legacy create form.
     */
    public function locations(Request $request): JsonResponse
    {
        $warehouses = $this->user($request)->accessibleWarehouses()
            ->where('status', '!=', Warehouse::STATUS_DELETED)
            ->withSum('stockLocations as stock_units', 'quantity')
            ->withCount(['stockLocations as product_count' => fn ($q) => $q->where('quantity', '>', 0)])
            ->orderBy('name')
            ->get();

        $stores = $this->user($request)->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->withSum('stockLocations as stock_units', 'quantity')
            ->withCount(['stockLocations as product_count' => fn ($q) => $q->where('quantity', '>', 0)])
            ->orderBy('name')
            ->get();

        return $this->ok([
            'locations' => [
                ...$warehouses->map(fn (Warehouse $w) => [
                    'type' => 'warehouse',
                    'id' => $w->id,
                    'code' => $w->warehouse_code,
                    'name' => $w->name,
                    'city' => $w->city,
                    'state' => $w->state,
                    'stock_units' => (int) ($w->stock_units ?? 0),
                    'product_count' => (int) ($w->product_count ?? 0),
                ])->all(),
                ...$stores->map(fn (Store $s) => [
                    'type' => 'store',
                    'id' => $s->id,
                    'code' => $s->store_id,
                    'name' => $s->name,
                    'city' => $s->physical_address,
                    'state' => null,
                    'stock_units' => (int) ($s->stock_units ?? 0),
                    'product_count' => (int) ($s->product_count ?? 0),
                ])->all(),
            ],
            'warehouses' => $warehouses->map(fn (Warehouse $w) => ['id' => $w->id, 'code' => $w->warehouse_code, 'name' => $w->name])->values()->all(),
            'stores' => $stores->map(fn (Store $s) => ['id' => $s->id, 'code' => $s->store_id, 'name' => $s->name])->values()->all(),
        ]);
    }

    /**
     * The create screen's product grid: everything with available stock at
     * one source location. Reads `StockLocation` rows first and falls back to
     * `Product.quantity` for products assigned to the location that have no
     * stock-location row yet — the same two sources dispatch reads.
     */
    public function sourceProducts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'location_type' => ['required', Rule::in(['warehouse', 'store'])],
            'location_id' => ['required', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $class = $this->locationClass($filters['location_type']);
        $location = $this->resolveLocation($request, $filters['location_type'], (int) $filters['location_id'], 'location_id');

        $rows = $this->stockLocationQuery($request, $class, $location->getKey(), $filters['q'] ?? null)
            ->with(['product.images', 'productVariant'])
            ->get()
            ->map(fn (StockLocation $row) => $this->sourceProductPayload(
                $row->product,
                $row->productVariant,
                (int) $row->quantity,
                $row->product_variant_id ? (int) $row->product_variant_id : null,
            ));

        $products = $rows->all();

        $covered = $this->stockLocationQuery($request, $class, $location->getKey())
            ->pluck('product_id')
            ->unique();

        $fallback = Product::query()
            ->where('business_id', $this->user($request)->business_id)
            ->where($class === Warehouse::class ? 'warehouse_id' : 'store_id', $location->getKey())
            ->where('quantity', '>', 0)
            ->when($class === Store::class, fn ($q) => $q->where('status', 'active'))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$term}%")
                ->orWhere('product_code', 'like', "%{$term}%")))
            ->whereNotIn('id', $covered)
            ->with('images')
            ->get()
            ->map(fn (Product $product) => $this->sourceProductPayload($product, null, (int) $product->quantity, null));

        $products = collect($products)->merge($fallback)->sortBy('name')->values();

        return $this->ok([
            'location' => $this->locationRef($class, $location),
            'products' => $products->all(),
        ]);
    }

    /**
     * The stock-location rows behind the create grid — the roadmap's
     * `stock-locations?source_type=&source_id=` read. `location_type` /
     * `location_id` are accepted as the same pair.
     */
    public function stockLocations(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'location_type' => ['nullable', Rule::in(['warehouse', 'store']), 'required_with:location_id'],
            'location_id' => ['nullable', 'integer', 'required_with:location_type'],
            'source_type' => ['nullable', Rule::in(['warehouse', 'store']), 'required_with:source_id'],
            'source_id' => ['nullable', 'integer', 'required_with:source_type'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $type = $filters['location_type'] ?? $filters['source_type'] ?? null;
        $id = $filters['location_id'] ?? $filters['source_id'] ?? null;

        if (! $type || ! $id) {
            return $this->error('A location_type + location_id (or source_type + source_id) pair is required.', 422);
        }

        $class = $this->locationClass($type);
        $location = $this->resolveLocation($request, $type, (int) $id, 'location_id');

        $rows = $this->stockLocationQuery($request, $class, $location->getKey(), $filters['q'] ?? null)
            ->with(['product.images', 'productVariant'])
            ->get();

        return $this->ok([
            'location' => $this->locationRef($class, $location),
            'stock_locations' => $rows->map(fn (StockLocation $row) => [
                'id' => $row->id,
                'product_id' => $row->product_id,
                'product_variant_id' => $row->product_variant_id,
                'product' => [
                    'id' => $row->product?->id,
                    'name' => $row->product?->name ?? 'Unknown product',
                    'product_code' => $row->product?->product_code,
                    'image_url' => $row->product?->primaryImage()?->path
                        ? asset('storage/'.$row->product->primaryImage()->path)
                        : null,
                ],
                'variant_label' => $row->productVariant?->variant_code,
                'quantity' => (int) $row->quantity,
                'min_quantity' => (int) $row->min_quantity,
            ])->all(),
            'total_units' => (int) $rows->sum('quantity'),
        ]);
    }

    /**
     * Shared state guard: every transition commits the target status and the
     * activity row together, then re-reads the transfer for the response.
     */
    private function transition(
        Request $request,
        StockTransfer $transfer,
        TransferStatus $target,
        string $action,
        string $message,
        string $description,
    ): JsonResponse {
        DB::transaction(function () use ($request, $transfer, $target, $action, $description) {
            $transfer->update(['status' => $target]);
            $this->logEvent($request, $transfer, $action, $description);
        });

        return $this->ok(['transfer' => $this->detail($request, $transfer->fresh())], $message);
    }

    /**
     * @param  array<int, array{product_id: int, quantity: int, product_variant_id?: int|null}>  $items
     * @return array<int, array{product_id: int, quantity: int, product_variant_id: int|null}>
     */
    private function validatedItems(Request $request, array $items): array
    {
        $user = $this->user($request);

        $products = Product::query()
            ->where('business_id', $user->business_id)
            ->whereIn('id', collect($items)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->all())
            ->get()
            ->keyBy('id');

        $errors = [];

        foreach (array_values($items) as $index => $item) {
            $product = $products->get((int) $item['product_id']);

            if (! $product) {
                $errors["items.{$index}.product_id"] = ['The selected product does not belong to your business.'];

                continue;
            }

            $variantId = isset($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;

            if ($variantId !== null && $variantId !== 0) {
                $variantExists = ProductVariant::query()
                    ->whereKey($variantId)
                    ->where('product_id', $product->id)
                    ->exists();

                if (! $variantExists) {
                    $errors["items.{$index}.product_variant_id"] = ['The selected variant does not belong to this product.'];
                }
            }

            if ($user->isRestrictedStaff() && ! $this->productAccessible($request, $product)) {
                $errors["items.{$index}.product_id"] = ['You do not have access to this product.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return collect($items)->map(fn ($item) => [
            'product_id' => (int) $item['product_id'],
            'product_variant_id' => isset($item['product_variant_id']) && (int) $item['product_variant_id'] > 0
                ? (int) $item['product_variant_id']
                : null,
            'quantity' => (int) $item['quantity'],
        ])->values()->all();
    }

    private function productAccessible(Request $request, Product $product): bool
    {
        $user = $this->user($request);

        if (! $user->isRestrictedStaff()) {
            return true;
        }

        if ($product->store_id && $user->accessibleStores()->whereKey($product->store_id)->exists()) {
            return true;
        }

        return (bool) ($product->warehouse_id && $user->accessibleWarehouses()->whereKey($product->warehouse_id)->exists());
    }

    private function listQuery(Request $request): Builder
    {
        $user = $this->user($request);

        $query = StockTransfer::query()->where('business_id', $user->business_id);

        // Restricted staff only see transfers touching a location they are
        // assigned to; legacy's index had no such scoping.
        if ($user->isRestrictedStaff()) {
            $warehouseIds = $this->accessibleLocationIds($request, 'warehouse');
            $storeIds = $this->accessibleLocationIds($request, 'store');

            $query->where(function ($q) use ($warehouseIds, $storeIds) {
                foreach ([[Warehouse::class, $warehouseIds, 'from'], [Warehouse::class, $warehouseIds, 'to'], [Store::class, $storeIds, 'from'], [Store::class, $storeIds, 'to']] as [$class, $ids, $side]) {
                    $q->orWhere(fn ($inner) => $inner
                        ->where("{$side}_location_type", $class)
                        ->whereIn("{$side}_location_id", $ids));
                }
            });
        }

        return $query;
    }

    private function authorizeTransfer(Request $request, StockTransfer $transfer): void
    {
        $user = $this->user($request);

        // Legacy never checked the business on show or on any transition.
        if ((int) $transfer->business_id !== (int) $user->business_id) {
            abort(403, 'You do not have access to this transfer.');
        }

        if ($user->isRestrictedStaff()
            && ! $this->locationAccessible($request, $transfer->from_location_type, (int) $transfer->from_location_id)
            && ! $this->locationAccessible($request, $transfer->to_location_type, (int) $transfer->to_location_id)) {
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

        if (! $this->locationAccessible($request, $type, $id)) {
            abort(403, 'You do not have access to this transfer.');
        }
    }

    /**
     * Accessible location ids for the list scope. The assigned-location
     * relations join `staff_assignments` (which also has an `id`), so the
     * pluck has to name the related table's key.
     *
     * @return array<int, int>
     */
    private function accessibleLocationIds(Request $request, string $type): array
    {
        $user = $this->user($request);

        $relation = $type === 'warehouse'
            ? $user->accessibleWarehouses()
            : $user->accessibleStores();

        return $relation->pluck($relation->getRelated()->getQualifiedKeyName())->map(fn ($id) => (int) $id)->all();
    }

    private function locationAccessible(Request $request, string $class, int $id): bool
    {
        $user = $this->user($request);

        return $class === Warehouse::class
            ? $user->accessibleWarehouses()->whereKey($id)->exists()
            : $user->accessibleStores()->whereKey($id)->exists();
    }

    private function resolveLocation(Request $request, string $type, int $id, string $field): Store|Warehouse
    {
        $user = $this->user($request);

        $query = $type === 'warehouse'
            ? $user->accessibleWarehouses()->where('status', '!=', Warehouse::STATUS_DELETED)
            : $user->accessibleStores()->where('status', '!=', Store::STATUS_DELETED);

        $location = $query->whereKey($id)->first();

        // A location outside the circle is reported as an invalid selection
        // rather than a 403, so ids cannot be probed for existence.
        if (! $location || ($user->business_id !== null && (int) $location->business_id !== (int) $user->business_id)) {
            throw ValidationException::withMessages([
                $field => ['The selected location is not available to you.'],
            ]);
        }

        return $location;
    }

    /**
     * Existing stock at the source for a line. When `seed` is true and the
     * product carries its stock on `Product.quantity` (the management product
     * form writes quantity directly and never creates a stock-location row),
     * a source row is created from that count — the same lazy convention POS
     * uses on first sale. Legacy listed those products as available and then
     * always failed the dispatch.
     */
    private function sourceStock(StockTransfer $transfer, StockTransferItem $item, bool $seed = false, bool $lock = true): ?StockLocation
    {
        $query = StockLocation::query()
            ->where('product_id', $item->product_id)
            ->where('locationable_type', $transfer->from_location_type)
            ->where('locationable_id', $transfer->from_location_id);

        if ($item->product_variant_id) {
            $query->where('product_variant_id', $item->product_variant_id);
        } else {
            $query->whereNull('product_variant_id');
        }

        $location = ($lock ? $query->lockForUpdate() : $query)->first();

        if ($location || ! $seed) {
            return $location;
        }

        $fallback = $this->productAssignmentQuantity($transfer->from_location_type, (int) $transfer->from_location_id, $item);

        if ($fallback === null || $fallback <= 0) {
            return null;
        }

        return StockLocation::create([
            'business_id' => $transfer->business_id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'locationable_type' => $transfer->from_location_type,
            'locationable_id' => $transfer->from_location_id,
            'quantity' => $fallback,
            'min_quantity' => 0,
        ]);
    }

    /**
     * Find or create the destination stock location for a line. The legacy
     * receive flow created it business-scoped, variant-aware and at quantity
     * zero, then let the ledger addition move it up.
     */
    private function destinationStock(StockTransfer $transfer, StockTransferItem $item): StockLocation
    {
        $query = StockLocation::query()
            ->where('product_id', $item->product_id)
            ->where('locationable_type', $transfer->to_location_type)
            ->where('locationable_id', $transfer->to_location_id);

        if ($item->product_variant_id) {
            $query->where('product_variant_id', $item->product_variant_id);
        } else {
            $query->whereNull('product_variant_id');
        }

        $location = $query->lockForUpdate()->first();

        if ($location) {
            return $location;
        }

        return StockLocation::create([
            'business_id' => $transfer->business_id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'locationable_type' => $transfer->to_location_type,
            'locationable_id' => $transfer->to_location_id,
            'quantity' => 0,
            'min_quantity' => 0,
        ]);
    }

    /**
     * `Product.quantity` (or the variant's own count) when the product is
     * parked at the given location, else null.
     */
    private function productAssignmentQuantity(string $locationType, int $locationId, StockTransferItem $item): ?int
    {
        $product = $item->product;

        if (! $product) {
            return null;
        }

        if ($item->product_variant_id) {
            $variant = $item->variant;

            return $variant && (int) $variant->product_id === (int) $product->id
                ? (int) $variant->quantity
                : null;
        }

        $column = $locationType === Warehouse::class ? 'warehouse_id' : 'store_id';

        return (int) $product->{$column} === $locationId ? (int) $product->quantity : null;
    }

    /**
     * Keep `Product.quantity`/`ProductVariant.quantity` in step with the
     * ledger. Query-builder writes on purpose: the Product model's saving hook
     * rejects a zero quantity while a store is attached, and POS decrements
     * the same way. The sync is symmetric on both ends — legacy only
     * decremented when the product's assignment matched the source, inflating
     * the count for drifted products.
     */
    private function syncProductQuantity(StockTransferItem $item, StockTransfer $transfer, int $qty, string $direction): void
    {
        if ($item->product_variant_id) {
            $variant = $item->variant;

            if (! $variant) {
                return;
            }

            if ($direction === 'remove') {
                ProductVariant::query()->whereKey($variant->id)->update([
                    'quantity' => max(0, (int) $variant->quantity - $qty),
                ]);
            } else {
                ProductVariant::query()->whereKey($variant->id)->increment('quantity', $qty);
            }

            return;
        }

        $product = $item->product;

        if (! $product) {
            return;
        }

        if ($direction === 'remove') {
            $before = (int) $product->quantity;
            $after = max(0, $before - $qty);

            Product::query()->whereKey($product->id)->update(['quantity' => $after]);

            // Legacy cleared the drained location once the product hit zero,
            // so a fully-moved product does not linger at the old location.
            $column = $transfer->from_location_type === Warehouse::class ? 'warehouse_id' : 'store_id';

            if ($after === 0 && (int) $product->{$column} === (int) $transfer->from_location_id) {
                Product::query()->whereKey($product->id)->update([$column => null]);
            }

            return;
        }

        // Receiving parks the product at the destination and adds the units.
        // Unlike legacy this never nulls the counterpart column: a new-stack
        // product legitimately belongs to both a store and a warehouse.
        $column = $transfer->to_location_type === Warehouse::class ? 'warehouse_id' : 'store_id';

        Product::query()->whereKey($product->id)->increment('quantity', $qty, [
            $column => (int) $transfer->to_location_id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(StockTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'transfer_code' => $transfer->transfer_code,
            'status' => $transfer->status->value,
            'status_label' => $transfer->status->label(),
            'from' => $this->locationRef($transfer->from_location_type, $transfer->fromLocation),
            'to' => $this->locationRef($transfer->to_location_type, $transfer->toLocation),
            'items_count' => (int) ($transfer->items_count ?? $transfer->items->count()),
            'total_units' => (int) ($transfer->total_units ?? $transfer->items->sum('quantity')),
            'requested_by' => $transfer->requester?->name,
            'created_at' => $transfer->created_at?->toISOString(),
            'updated_at' => $transfer->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Request $request, StockTransfer $transfer): array
    {
        $transfer->loadMissing(['fromLocation', 'toLocation', 'requester', 'approver', 'dispatcher', 'receiver', 'items.product.images', 'items.variant']);

        return [
            ...$this->summary($transfer),
            'notes' => $transfer->notes,
            'rejection_reason' => $transfer->rejection_reason,
            'approved_by' => $transfer->approver?->name,
            'dispatched_by' => $transfer->dispatcher?->name,
            'received_by' => $transfer->receiver?->name,
            'items' => $transfer->items->map(function (StockTransferItem $item) use ($transfer) {
                $approved = $item->approved_quantity;

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'product' => [
                        'id' => $item->product?->id,
                        'name' => $item->product?->name ?? 'Unknown product',
                        'product_code' => $item->product?->product_code,
                        'image_url' => $item->product?->primaryImage()?->path
                            ? asset('storage/'.$item->product->primaryImage()->path)
                            : null,
                    ],
                    'variant_label' => $item->variant?->variant_code,
                    'quantity' => (int) $item->quantity,
                    'approved_quantity' => $approved !== null ? (int) $approved : null,
                    'adjusted' => $approved !== null && (int) $approved < (int) $item->quantity,
                    'available_at_source' => (int) ($this->sourceStock($transfer, $item, lock: false)?->quantity
                        ?? $this->productAssignmentQuantity($transfer->from_location_type, (int) $transfer->from_location_id, $item)
                        ?? 0),
                ];
            })->values()->all(),
            'timeline' => $this->timeline($transfer),
            'movements' => StockMovement::query()
                ->where('reference_type', StockTransfer::class)
                ->where('reference_id', $transfer->id)
                ->with(['product:id,name', 'performedBy:id,name'])
                ->orderBy('id')
                ->get()
                ->map(fn (StockMovement $m) => [
                    'id' => $m->id,
                    'movement_code' => $m->movement_code,
                    'type' => $m->type instanceof \BackedEnum ? $m->type->value : $m->type,
                    'product' => $m->product?->name,
                    'quantity' => (int) $m->quantity,
                    'balance_before' => $m->balance_before !== null ? (int) $m->balance_before : null,
                    'balance_after' => $m->balance_after !== null ? (int) $m->balance_after : null,
                    'performed_by' => $m->performedBy?->name,
                    'created_at' => $m->created_at?->toISOString(),
                ])->all(),
            'actions' => $this->actions($request, $transfer),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function timeline(StockTransfer $transfer): array
    {
        $logs = ActivityLog::query()
            ->where('subject_type', StockTransfer::class)
            ->where('subject_id', $transfer->id)
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->keyBy('action');

        $logStep = function (string $action) use ($logs): ?array {
            $log = $logs->get($action);

            return $log ? [
                'who' => $log->user?->name,
                'at' => $log->created_at?->toISOString(),
            ] : null;
        };

        $approvedLog = $logStep('transfer.approved');
        $steps = [];

        $steps[] = [
            'key' => 'created',
            'label' => 'Created',
            'done' => true,
            'who' => $transfer->requester?->name,
            'at' => $transfer->created_at?->toISOString(),
        ];

        $submitted = $logStep('transfer.submitted');
        $submittedDone = $submitted !== null || in_array($transfer->status, [
            TransferStatus::PENDING, TransferStatus::APPROVED, TransferStatus::AWAITING_ACKNOWLEDGMENT,
            TransferStatus::DISPATCHED, TransferStatus::RECEIVED, TransferStatus::REJECTED,
        ], true);

        $steps[] = [
            'key' => 'submitted',
            'label' => 'Submitted',
            'done' => $submittedDone,
            'who' => $submitted['who'] ?? null,
            'at' => $submitted['at'] ?? null,
        ];

        $approvedDone = $approvedLog !== null
            || in_array($transfer->status, [TransferStatus::APPROVED, TransferStatus::AWAITING_ACKNOWLEDGMENT, TransferStatus::DISPATCHED, TransferStatus::RECEIVED], true);

        // Legacy did not show the (still pending) approval step on a rejected
        // or cancelled transfer.
        if ($approvedDone || ! $transfer->isRejected() && ! $transfer->isCancelled()) {
            $steps[] = [
                'key' => 'approved',
                'label' => 'Approved',
                'done' => $approvedDone,
                'who' => $approvedLog['who'] ?? ($approvedDone ? $transfer->approver?->name : null),
                'at' => $approvedLog['at'] ?? null,
            ];
        }

        // The awaiting-acknowledgment and acknowledged steps only exist once
        // quantities have been adjusted — legacy showed them the same way.
        if ($transfer->isAwaitingAcknowledgment() || $logStep('transfer.acknowledged') !== null) {
            $steps[] = [
                'key' => 'awaiting_acknowledgment',
                'label' => 'Awaiting acknowledgement',
                'done' => true,
                'who' => $approvedLog['who'] ?? null,
                'at' => $approvedLog['at'] ?? null,
            ];
        }

        $acknowledged = $logStep('transfer.acknowledged');

        if ($acknowledged !== null) {
            $steps[] = [
                'key' => 'acknowledged',
                'label' => 'Acknowledged',
                'done' => true,
                'who' => $acknowledged['who'],
                'at' => $acknowledged['at'],
            ];
        }

        $dispatched = $logStep('transfer.dispatched');
        $dispatchedDone = $dispatched !== null || $transfer->dispatcher !== null || $transfer->status === TransferStatus::RECEIVED;

        if ($dispatchedDone || in_array($transfer->status, [TransferStatus::APPROVED, TransferStatus::AWAITING_ACKNOWLEDGMENT], true) || $acknowledged !== null) {
            $steps[] = [
                'key' => 'dispatched',
                'label' => 'Dispatched',
                'done' => $dispatchedDone,
                'who' => $dispatched['who'] ?? ($dispatchedDone ? $transfer->dispatcher?->name : null),
                'at' => $dispatched['at'] ?? null,
            ];
        }

        $received = $logStep('transfer.received');
        $receivedDone = $received !== null || $transfer->receiver !== null;

        if ($receivedDone || $dispatchedDone) {
            $steps[] = [
                'key' => 'received',
                'label' => 'Received',
                'done' => $receivedDone,
                'who' => $received['who'] ?? ($receivedDone ? $transfer->receiver?->name : null),
                'at' => $received['at'] ?? null,
            ];
        }

        if ($transfer->isRejected()) {
            $rejected = $logStep('transfer.rejected');

            $steps[] = [
                'key' => 'rejected',
                'label' => 'Rejected',
                'done' => true,
                'who' => $rejected['who'] ?? $transfer->approver?->name,
                'at' => $rejected['at'] ?? $transfer->updated_at?->toISOString(),
            ];
        }

        if ($transfer->isCancelled()) {
            $cancelled = $logStep('transfer.cancelled');

            $steps[] = [
                'key' => 'cancelled',
                'label' => 'Cancelled',
                'done' => true,
                'who' => $cancelled['who'] ?? null,
                'at' => $cancelled['at'] ?? $transfer->updated_at?->toISOString(),
            ];
        }

        return $steps;
    }

    /**
     * What the signed-in user may do with this transfer right now — state
     * guard and permission together, so the SPA never renders a button the
     * API would refuse.
     *
     * @return array<string, bool>
     */
    private function actions(Request $request, StockTransfer $transfer): array
    {
        $user = $this->user($request);

        return [
            'approve' => $transfer->canBeApproved() && $user->can('transfers approve'),
            'reject' => $transfer->canBeRejected() && $user->can('transfers approve'),
            // Legacy gated submit, cancel and acknowledge on `transfers create`.
            'submit' => $transfer->canBeSubmitted() && $user->can('transfers create'),
            'acknowledge' => $transfer->canBeAcknowledged() && $user->can('transfers create'),
            'dispatch' => $transfer->canBeDispatched() && $user->can('transfers dispatch'),
            'receive' => $transfer->canBeReceived() && $user->can('transfers receive'),
            'cancel' => $transfer->canBeCancelled() && $user->can('transfers create'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function locationRef(string $class, ?Model $location): ?array
    {
        if (! $location) {
            return null;
        }

        $isWarehouse = $class === Warehouse::class || $location instanceof Warehouse;

        return [
            'type' => $isWarehouse ? 'warehouse' : 'store',
            'id' => $location->getKey(),
            'code' => $isWarehouse
                ? ($location instanceof Warehouse ? $location->warehouse_code : null)
                : ($location instanceof Store ? $location->store_id : null),
            'name' => $location instanceof Warehouse || $location instanceof Store ? $location->name : null,
        ];
    }

    /**
     * @param  array{product_id: int, product_variant_id: int|null, quantity: int}  $line
     * @return array<string, mixed>
     */
    private function sourceProductPayload(?Product $product, ?ProductVariant $variant, int $available, ?int $variantId): array
    {
        return [
            'product_id' => $product?->id,
            'product_variant_id' => $variantId,
            'name' => $product?->name ?? 'Unknown product',
            'product_code' => $product?->product_code,
            'image_url' => $product?->primaryImage()?->path
                ? asset('storage/'.$product->primaryImage()->path)
                : null,
            'available' => $available,
            'variant_label' => $variant?->variant_code,
            'has_variants' => (bool) ($product?->has_variants ?? false),
        ];
    }

    /**
     * @return Builder<StockLocation>
     */
    private function stockLocationQuery(Request $request, string $class, int $locationId, ?string $term = null): Builder
    {
        return StockLocation::query()
            ->where('business_id', $this->user($request)->business_id)
            ->where('locationable_type', $class)
            ->where('locationable_id', $locationId)
            ->where('quantity', '>', 0)
            ->when($term, fn (Builder $q) => $q->whereHas('product', fn ($p) => $p
                ->where('name', 'like', "%{$term}%")
                ->orWhere('product_code', 'like', "%{$term}%")));
    }

    private function locationClass(string $type): string
    {
        return $type === 'warehouse' ? Warehouse::class : Store::class;
    }

    /**
     * @return array<int, string>
     */
    private function statusValues(): array
    {
        return array_map(fn (TransferStatus $s) => $s->value, TransferStatus::cases());
    }

    private function logEvent(Request $request, StockTransfer $transfer, string $action, string $description): void
    {
        $user = $this->user($request);

        // Legacy wrote `transfer.*` lines to the Laravel log; keep those names
        // and record the same event where the timeline reads it back.
        Log::info($action, [
            'transfer_id' => $transfer->id,
            'user_id' => $user->id,
        ]);

        ActivityLog::create([
            'user_id' => $user->id,
            'business_id' => $transfer->business_id,
            'action' => $action,
            'subject_type' => StockTransfer::class,
            'subject_id' => $transfer->id,
            'description' => $description,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);
    }
}
