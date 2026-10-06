<?php

namespace App\Repositories\Management;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WS-15 — reads and row-level persistence helpers for the transfers module.
 *
 * Tenant scoping (business, plus assigned locations for restricted staff), the
 * list filters/eager loads/aggregates, the create grid's two stock sources
 * (StockLocation rows first, Product.quantity for products parked at the
 * location with no row yet), the destination find-or-create, the row locks a
 * transition needs and the per-line assignment maths live here.
 *
 * Reads and query building only for the workflow side: the DB::transaction
 * calls and the two-pass dispatch algorithm belong to
 * App\Services\Management\StockTransferService, and nothing in this layer
 * aborts an HTTP request. The find-or-create helpers are deliberately separate
 * from that algorithm so the lock (`lockForUpdate`) stays on the row the
 * caller is about to mutate.
 */
final class StockTransferRepository
{
    /**
     * Transfers the user may see: business-scoped, plus the assigned-location
     * restriction for restricted staff. Legacy's index had no such scoping.
     *
     * @return Builder<StockTransfer>
     */
    public function scopedQuery(User $user): Builder
    {
        $query = StockTransfer::query()->where('business_id', $user->business_id);

        // Restricted staff only see transfers touching a location they are
        // assigned to.
        if ($user->isRestrictedStaff()) {
            $warehouseIds = $user->accessibleWarehouseIds()->map(fn ($id) => (int) $id)->all();
            $storeIds = $user->accessibleStoreIds()->map(fn ($id) => (int) $id)->all();

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

    /**
     * The list: status tab, code/requester search, the location pair filter,
     * eager loads and aggregates.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<StockTransfer>
     */
    public function paginateForUser(User $user, array $filters): LengthAwarePaginator
    {
        $query = $this->scopedQuery($user)
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
            $class = $filters['location_type'] === 'warehouse' ? Warehouse::class : Store::class;
            $id = (int) $filters['location_id'];

            $query->where(function ($q) use ($class, $id) {
                $q->where(fn ($side) => $side->where('from_location_type', $class)->where('from_location_id', $id))
                    ->orWhere(fn ($side) => $side->where('to_location_type', $class)->where('to_location_id', $id));
            });
        }

        return $query->latest('id')->paginate($filters['per_page'] ?? 20)->withQueryString();
    }

    /**
     * Per-status counts for the tab badges. Scoped like the list but never
     * filtered by the active tab/search, so the totals stay stable.
     *
     * @return array<string, int>
     */
    public function statusCounts(User $user): array
    {
        return $this->scopedQuery($user)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * The relation set every detail payload renders.
     */
    public function loadDetail(StockTransfer $transfer): StockTransfer
    {
        return $transfer->loadMissing(['fromLocation', 'toLocation', 'requester', 'approver', 'dispatcher', 'receiver', 'items.product.images', 'items.variant']);
    }

    /**
     * The `transfer.*` activity rows the timeline reads back, keyed by action.
     *
     * @return Collection<string, ActivityLog>
     */
    public function activityLogs(StockTransfer $transfer): Collection
    {
        return ActivityLog::query()
            ->where('subject_type', StockTransfer::class)
            ->where('subject_id', $transfer->id)
            ->with('user:id,name')
            ->orderBy('id')
            ->get()
            ->keyBy('action');
    }

    /**
     * Ledger movements written against this transfer, oldest first.
     *
     * @return Collection<int, StockMovement>
     */
    public function movements(StockTransfer $transfer): Collection
    {
        return StockMovement::query()
            ->where('reference_type', StockTransfer::class)
            ->where('reference_id', $transfer->id)
            ->with(['product:id,name', 'performedBy:id,name'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Accessible warehouses and stores for the From/To pickers, with the stock
     * each holds. Restricted staff see only their assigned locations, exactly
     * like the legacy create form.
     *
     * @return array{warehouses: Collection<int, Warehouse>, stores: Collection<int, Store>}
     */
    public function accessibleLocations(User $user): array
    {
        $warehouses = $user->accessibleWarehouses()
            ->where('status', '!=', Warehouse::STATUS_DELETED)
            ->withSum('stockLocations as stock_units', 'quantity')
            ->withCount(['stockLocations as product_count' => fn ($q) => $q->where('quantity', '>', 0)])
            ->orderBy('name')
            ->get();

        $stores = $user->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->withSum('stockLocations as stock_units', 'quantity')
            ->withCount(['stockLocations as product_count' => fn ($q) => $q->where('quantity', '>', 0)])
            ->orderBy('name')
            ->get();

        return ['warehouses' => $warehouses, 'stores' => $stores];
    }

    /**
     * Stock-location rows at one location, searchable by product name/code.
     *
     * @return Builder<StockLocation>
     */
    public function stockLocationsAt(User $user, string $class, int $locationId, ?string $term = null): Builder
    {
        return StockLocation::query()
            ->where('business_id', $user->business_id)
            ->where('locationable_type', $class)
            ->where('locationable_id', $locationId)
            ->where('quantity', '>', 0)
            ->when($term, fn (Builder $q) => $q->whereHas('product', fn ($p) => $p
                ->where('name', 'like', "%{$term}%")
                ->orWhere('product_code', 'like', "%{$term}%")));
    }

    /**
     * Product ids already covered by a stock-location row at the location (no
     * search term applied — the fallback must not re-list them through a term).
     *
     * @return Collection<int, int>
     */
    public function stockedProductIds(User $user, string $class, int $locationId): Collection
    {
        return $this->stockLocationsAt($user, $class, $locationId)->pluck('product_id')->unique();
    }

    /**
     * Products parked at the location via `Product.quantity` that have no
     * stock-location row yet — the second source the create grid reads.
     *
     * @param  Collection<int, int>  $covered
     * @return Collection<int, Product>
     */
    public function sourceProductFallback(User $user, string $class, int $locationId, ?string $term, Collection $covered): Collection
    {
        return Product::query()
            ->where('business_id', $user->business_id)
            ->where($class === Warehouse::class ? 'warehouse_id' : 'store_id', $locationId)
            ->where('quantity', '>', 0)
            ->when($class === Store::class, fn ($q) => $q->where('status', 'active'))
            ->when($term, fn ($q, $search) => $q->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('product_code', 'like', "%{$search}%")))
            ->whereNotIn('id', $covered)
            ->with('images')
            ->get();
    }

    /**
     * One location from the user's accessible set, not-deleted only.
     *
     * A location outside the circle is reported by the caller as an invalid
     * selection rather than a 403, so ids cannot be probed for existence.
     */
    public function findAccessibleLocation(User $user, string $type, int $id): Store|Warehouse|null
    {
        $query = $type === 'warehouse'
            ? $user->accessibleWarehouses()->where('status', '!=', Warehouse::STATUS_DELETED)
            : $user->accessibleStores()->where('status', '!=', Store::STATUS_DELETED);

        return $query->whereKey($id)->first();
    }

    /**
     * Products of this business by id, keyed by id for the item validator.
     *
     * @param  array<int, int>  $productIds
     * @return Collection<int, Product>
     */
    public function productsFor(User $user, array $productIds): Collection
    {
        return Product::query()
            ->where('business_id', $user->business_id)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * Whether a variant id really belongs to the given product.
     */
    public function variantExists(int $productId, int $variantId): bool
    {
        return ProductVariant::query()
            ->whereKey($variantId)
            ->where('product_id', $productId)
            ->exists();
    }

    /**
     * The transfer a transition serializes on. The caller owns the surrounding
     * DB::transaction; the route-bound instance was read before this lock
     * existed, so the caller must re-check its state guard on this row.
     */
    public function lockTransfer(int $id): StockTransfer
    {
        return StockTransfer::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Existing stock at the source for a line. When `seed` is true and the
     * product carries its stock on `Product.quantity` (the management product
     * form writes quantity directly and never creates a stock-location row),
     * a source row is created from that count — the same lazy convention POS
     * uses on first sale. Legacy listed those products as available and then
     * always failed the dispatch.
     */
    public function sourceStock(StockTransfer $transfer, StockTransferItem $item, bool $seed = false, bool $lock = true): ?StockLocation
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

        $fallback = $this->assignmentQuantity($transfer->from_location_type, (int) $transfer->from_location_id, $item);

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
    public function destinationStock(StockTransfer $transfer, StockTransferItem $item): StockLocation
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
    public function assignmentQuantity(string $locationType, int $locationId, StockTransferItem $item): ?int
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
     * What the detail payload reports as `available_at_source`: an existing
     * stock-location row's count, else the product's assigned quantity, else
     * zero — the same two sources the dispatch pre-flight reads, without the
     * lock. Keyed by item id for the response resource.
     *
     * @return array<int, int>
     */
    public function availabilityMap(StockTransfer $transfer): array
    {
        return $transfer->items
            ->mapWithKeys(fn (StockTransferItem $item) => [$item->id => (int) ($this->sourceStock($transfer, $item, lock: false)?->quantity
                ?? $this->assignmentQuantity($transfer->from_location_type, (int) $transfer->from_location_id, $item)
                ?? 0)])
            ->all();
    }
}
