<?php

namespace App\Services\Management;

use App\Enums\TransferStatus;
use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Repositories\Management\StockTransferRepository;
use App\Services\StockLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * WS-15 — the inventory transfer / "Stock Adjustment" workflows.
 *
 * The state machine (draft → pending → approved | awaiting_acknowledgment →
 * approved → dispatched → received, plus rejected/cancelled), the per-line
 * approved-quantity semantics, the awaiting-acknowledgment loop, the two-pass
 * dispatch (pre-flight shortage pass, then mutation) and every DB::transaction
 * boundary live here. The controller keeps the HTTP contract — status codes,
 * refusal messages, the envelope, the 403/409 guards — and
 * App\Repositories\Management\StockTransferRepository supplies the reads and
 * row-level find-or-create helpers.
 *
 * Fixes over legacy, kept at their sites: dispatch surfaces every short line
 * in one 422 (legacy swallowed them into a generic flash), dispatch seeds a
 * missing source location from `Product.quantity` (legacy listed those
 * products then always failed them) and the `Product.quantity` sync is
 * symmetric on both ends (legacy decremented only when the product's
 * assignment matched the source, inflating the count for drifted products).
 *
 * Order is load-bearing: the ledger gives each removal/addition an idempotency
 * key of (reference, location, direction), so each line must be processed
 * once, in order, inside the same transaction that flips the status.
 *
 * The acting user is passed in by the controller. The admin console delegates
 * its transitions with a business-scoped copy of the admin identity as the
 * request user, so every stamp this service writes (`approved_by`,
 * `dispatched_by`, the movements' `performed_by`, the activity rows) names the
 * admin who acted, never the business owner.
 */
final class StockTransferService
{
    public function __construct(
        private readonly StockTransferRepository $repository,
        private readonly StockLedgerService $ledger,
    ) {}

    /**
     * Resolve both ends of a create request in field order and refuse the
     * same location twice. The pre-refactor controller threw these in exactly
     * this sequence, before it validated the items.
     *
     * @return array{0: Store|Warehouse, 1: Store|Warehouse}
     */
    public function resolveLocations(User $user, array $data): array
    {
        $from = $this->resolveLocation($user, $data['from_location_type'], (int) $data['from_location_id'], 'from_location_id');
        $to = $this->resolveLocation($user, $data['to_location_type'], (int) $data['to_location_id'], 'to_location_id');

        if ($from->is($to)) {
            throw ValidationException::withMessages([
                'to_location_id' => ['Source and destination cannot be the same location.'],
            ]);
        }

        return [$from, $to];
    }

    /**
     * One end of a create request, from the user's accessible locations only
     * and not-deleted. A location outside the circle is reported as an invalid
     * selection rather than a 403, so ids cannot be probed for existence.
     */
    public function resolveLocation(User $user, string $type, int $id, string $field): Store|Warehouse
    {
        $location = $this->repository->findAccessibleLocation($user, $type, $id);

        if (! $location || ($user->business_id !== null && (int) $location->business_id !== (int) $user->business_id)) {
            throw ValidationException::withMessages([
                $field => ['The selected location is not available to you.'],
            ]);
        }

        return $location;
    }

    /**
     * Per-line pre-flight on create: the product must belong to the business,
     * the variant to the product, and a restricted staff member must be able
     * to reach the product. Errors are collected against the item's index,
     * exactly as the controller threw them.
     *
     * @param  array<int, array{product_id: int, quantity: int, product_variant_id?: int|null}>  $items
     * @return array<int, array{product_id: int, quantity: int, product_variant_id: int|null}>
     */
    public function validatedItems(User $user, array $items): array
    {
        $products = $this->repository->productsFor(
            $user,
            collect($items)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->all(),
        );

        $errors = [];

        foreach (array_values($items) as $index => $item) {
            $product = $products->get((int) $item['product_id']);

            if (! $product) {
                $errors["items.{$index}.product_id"] = ['The selected product does not belong to your business.'];

                continue;
            }

            $variantId = isset($item['product_variant_id']) ? (int) $item['product_variant_id'] : null;

            if ($variantId !== null && $variantId !== 0) {
                if (! $this->repository->variantExists($product->id, $variantId)) {
                    $errors["items.{$index}.product_variant_id"] = ['The selected variant does not belong to this product.'];
                }
            }

            if ($user->isRestrictedStaff() && ! $this->productAccessible($user, $product)) {
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

    /**
     * Create the transfer and its lines in one transaction, writing the
     * legacy `transfer.created` activity row alongside.
     *
     * @param  array<int, array{product_id: int, quantity: int, product_variant_id: int|null}>  $items
     */
    public function create(
        Request $request,
        User $user,
        array $data,
        Store|Warehouse $from,
        Store|Warehouse $to,
        array $items,
        bool $submitted,
    ): StockTransfer {
        return DB::transaction(function () use ($request, $user, $data, $from, $to, $items, $submitted) {
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

            $this->logEvent($request, $transfer, $user, 'transfer.created', 'Transfer created from '.$from->name.' to '.$to->name);

            return $transfer;
        });
    }

    /**
     * Apply the approval in one transaction and return whether any line was
     * adjusted below its requested quantity.
     *
     * @param  array<int|string, int>  $approvedQuantities  keyed by item id
     */
    public function approve(Request $request, StockTransfer $transfer, User $user, array $approvedQuantities): bool
    {
        return DB::transaction(function () use ($request, $transfer, $user, $approvedQuantities) {
            $transfer->loadMissing('items');

            foreach ($transfer->items as $item) {
                // Legacy defaults every line to its requested quantity and
                // clamps any higher adjustment back down.
                $approved = (int) ($approvedQuantities[$item->id] ?? $item->quantity);
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
                $user,
                'transfer.approved',
                $adjusted
                    ? 'Quantities adjusted and sent for acknowledgement'
                    : 'Transfer approved',
            );

            return $adjusted;
        });
    }

    /**
     * Reject with the mandatory reason, stamping the approver and recording
     * the legacy `transfer.rejected` activity row — one transaction.
     */
    public function reject(Request $request, StockTransfer $transfer, User $user, string $reason): void
    {
        DB::transaction(function () use ($request, $transfer, $user, $reason) {
            $transfer->update([
                'status' => TransferStatus::REJECTED,
                'rejection_reason' => $reason,
                'approved_by' => $user->id,
            ]);

            $this->logEvent($request, $transfer, $user, 'transfer.rejected', 'Transfer rejected: '.$reason);
        });
    }

    /**
     * Shared state transition: every move commits the target status and the
     * activity row together, then the controller re-reads the transfer for the
     * response.
     */
    public function transition(Request $request, StockTransfer $transfer, User $user, TransferStatus $target, string $action, string $description): void
    {
        DB::transaction(function () use ($request, $transfer, $user, $target, $action, $description) {
            $transfer->update(['status' => $target]);
            $this->logEvent($request, $transfer, $user, $action, $description);
        });
    }

    /**
     * Dispatch: pre-flight every line for shortage, then move stock through
     * the ledger and sync the product counts — all inside one transaction,
     * under a row lock taken first.
     *
     * Returns `['conflict' => true]` when the locked row refuses the
     * transition and `['shortages' => [...]]` when any line is short; both are
     * mapped to their HTTP errors by the controller. A line drained between
     * the two passes throws so the transaction rolls back the lines already
     * removed.
     *
     * @return array{conflict?: true, shortages?: array<int, string>, ok?: true}
     */
    public function dispatch(Request $request, StockTransfer $transfer, User $user): array
    {
        $transfer->loadMissing(['items.product', 'items.variant', 'toLocation']);

        $destination = $transfer->toLocation?->name ?? 'destination';

        return DB::transaction(function () use ($request, $transfer, $user, $destination) {
            // Serialize concurrent transition attempts on the same transfer;
            // the route-bound instance was read before this lock existed.
            $locked = $this->repository->lockTransfer($transfer->getKey());

            if (! $locked->canBeDispatched()) {
                return ['conflict' => true];
            }

            // Pre-flight every line before touching stock, so the caller gets
            // every shortage in one response instead of legacy's generic
            // "Failed to dispatch transfer." flash.
            $shortages = [];

            foreach ($transfer->items as $item) {
                $qty = (int) ($item->approved_quantity ?? $item->quantity);
                $source = $this->repository->sourceStock($transfer, $item);

                // Same two sources the mutation pass reads: an existing
                // stock-location row, else the product's assigned quantity
                // (which the mutation pass will seed the location from).
                $available = $source?->quantity
                    ?? $this->repository->assignmentQuantity($transfer->from_location_type, (int) $transfer->from_location_id, $item)
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
                $source = $this->repository->sourceStock($transfer, $item, seed: true);

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

            $this->logEvent($request, $transfer, $user, 'transfer.dispatched', 'Transfer dispatched to '.$destination);

            return ['ok' => true];
        });
    }

    /**
     * Receive: create/fill the destination location per line through the
     * ledger and sync the product counts — one transaction, row lock first.
     * Returns true when the locked row refuses the transition (the controller
     * maps it to the 409).
     */
    public function receive(Request $request, StockTransfer $transfer, User $user): bool
    {
        $transfer->loadMissing(['items.product', 'items.variant', 'fromLocation']);

        $source = $transfer->fromLocation?->name ?? 'source';

        return DB::transaction(function () use ($request, $transfer, $user, $source) {
            $locked = $this->repository->lockTransfer($transfer->getKey());

            if (! $locked->canBeReceived()) {
                return true;
            }

            foreach ($transfer->items as $item) {
                $qty = (int) ($item->approved_quantity ?? $item->quantity);

                $destination = $this->repository->destinationStock($transfer, $item);

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

            $this->logEvent($request, $transfer, $user, 'transfer.received', 'Transfer received from '.$source);

            return false;
        });
    }

    private function productAccessible(User $user, Product $product): bool
    {
        if (! $user->isRestrictedStaff()) {
            return true;
        }

        if ($product->store_id && $user->accessibleStores()->whereKey($product->store_id)->exists()) {
            return true;
        }

        return (bool) ($product->warehouse_id && $user->accessibleWarehouses()->whereKey($product->warehouse_id)->exists());
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

    private function logEvent(Request $request, StockTransfer $transfer, User $user, string $action, string $description): void
    {
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
