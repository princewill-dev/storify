<?php

namespace App\Repositories\Management;

use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockVisibilityService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WS-29 — the queries behind StockVisibilityController.
 *
 * Moved here from the controller's private helpers: the scope query both list
 * endpoints compose on (accessible locations + non-digital product + the
 * product/search filters), the state counts read off it, the display rows for
 * the selected tab, the product fallback query behind rows with no
 * stock-location entry, the editor's list query (state filter, eager loads,
 * sort, pagination), the filter-picker locations, the tenancy-scoped finders
 * behind the store_id / warehouse_id / product_id guards and the
 * lockForUpdate() fetches the min-level writes run inside their transactions.
 *
 * It depends on {@see StockVisibilityService} on purpose: the low/out SQL and
 * the morphed store/warehouse location scope live there so the summary, the
 * drill-down and the editor cannot disagree about what "low" means. This layer
 * composes those rules instead of re-deriving them.
 *
 * Query building only: no DB::transaction (that belongs to
 * StockMinLevelService) and no abort() here — the finders return null for a
 * value outside the caller's circle and the controller turns that into the
 * pre-refactor 403.
 */
final class StockVisibilityRepository
{
    public function __construct(private readonly StockVisibilityService $stock) {}

    /**
     * The scope query both list endpoints build on, without any state filter:
     * the tab counts are read off it so selecting "out of stock" does not
     * blank the low tab. Digital products are never part of a stock read, and
     * the product_id/q filters narrow through the product relation.
     *
     * @return Builder<StockLocation>
     */
    public function locationQuery(
        User $user,
        ?int $storeId,
        ?int $warehouseId,
        ?Product $product,
        ?string $term,
    ): Builder {
        return $this->stock->accessibleLocationQuery($user, $storeId, $warehouseId)
            ->whereHas('product', fn (Builder $query) => $query
                ->where('is_digital', false)
                ->when($product, fn (Builder $inner) => $inner->whereKey($product->id))
                ->when($term, fn (Builder $inner, $search) => $inner->where(fn (Builder $name) => $name
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('product_code', 'like', "%{$search}%"))));
    }

    /**
     * Locations in one state, counted off a base query. Always a single state
     * (never the "attention" union), so the two tabs keep their own counts.
     */
    public function countLocations(Builder $base, string $state): int
    {
        $query = clone $base;
        $this->stock->applyLocationState($query, $state);

        return $query->count();
    }

    /**
     * Display rows for the selected tab: one state, or the "attention" union
     * of low and out, with the relations the row shape renders.
     *
     * @return Collection<int, StockLocation>
     */
    public function displayLocations(Builder $base, string $state): Collection
    {
        $query = clone $base;
        $query->with(['product.images', 'productVariant:id,variant_code', 'locationable']);
        $this->applyStateFilter($query, $state);

        return $query->get();
    }

    /**
     * Products without a stock-location row at the locations in scope — the
     * legacy product-level stock fallback (WS-15's create grid falls back the
     * same way). Returned without a state filter; the count and list methods
     * below apply one, so a business with a ledger does not get its whole
     * catalogue back.
     *
     * @return Builder<Product>
     */
    public function fallbackProductQuery(
        User $user,
        ?int $storeId,
        ?int $warehouseId,
        ?Product $product,
        ?string $term,
    ): Builder {
        $covered = $this->stock->accessibleLocationQuery($user, $storeId, $warehouseId)
            ->select('stock_locations.product_id');

        return Product::query()
            ->where('business_id', $user->business_id)
            ->whereNotIn('products.id', $covered)
            ->with(['store:id,name,store_id', 'warehouse:id,warehouse_code,name', 'variants:id,product_id,quantity', 'images'])
            ->when($storeId !== null, fn (Builder $inner) => $inner->where('store_id', $storeId))
            ->when($warehouseId !== null, fn (Builder $inner) => $inner->where('warehouse_id', $warehouseId))
            ->when($storeId === null && $warehouseId === null, fn (Builder $inner) => $inner
                ->whereIn('store_id', $this->stock->accessibleStoreIds($user)))
            ->when($product, fn (Builder $inner) => $inner->whereKey($product->id))
            ->when($term, fn (Builder $inner, $search) => $inner->where(fn (Builder $name) => $name
                ->where('name', 'like', "%{$search}%")
                ->orWhere('product_code', 'like', "%{$search}%")));
    }

    /**
     * The fallback rows in one state, eager loaded and ready for the shared
     * product row shape. Attention-aware, like displayLocations().
     *
     * @return Collection<int, Product>
     */
    public function fallbackProducts(
        User $user,
        ?int $storeId,
        ?int $warehouseId,
        ?Product $product,
        ?string $term,
        string $state,
    ): Collection {
        $query = $this->fallbackProductQuery($user, $storeId, $warehouseId, $product, $term);
        $this->applyProductStateFilter($query, $state);

        return $query->get();
    }

    /**
     * Count of fallback products in one state, read off the same query as the
     * rows so the tab counts and the list cannot drift apart.
     */
    public function countFallbackProducts(
        User $user,
        ?int $storeId,
        ?int $warehouseId,
        ?Product $product,
        ?string $term,
        string $state,
    ): int {
        $query = $this->fallbackProductQuery($user, $storeId, $warehouseId, $product, $term);
        $this->applyProductStateFilter($query, $state);

        return $query->count();
    }

    /**
     * The editor header's three counts: the whole filtered set, then the two
     * attention states within it.
     *
     * @return array{total: int, low_stock: int, out_of_stock: int}
     */
    public function locationCounts(Builder $base): array
    {
        return [
            'total' => (clone $base)->count(),
            'low_stock' => $this->countLocations($base, StockVisibilityService::STATE_LOW),
            'out_of_stock' => $this->countLocations($base, StockVisibilityService::STATE_OUT),
        ];
    }

    /**
     * The editor list: state filter, eager loads, the selected ordering and
     * pagination. Returns models; the shared row shape is applied after this.
     *
     * @return LengthAwarePaginator<StockLocation>
     */
    public function paginateLevels(Builder $base, string $state, ?string $sort, int $perPage): LengthAwarePaginator
    {
        $query = clone $base;

        if ($state !== 'all') {
            $this->stock->applyLocationState($query, $state);
        }

        $query->with(['product.images', 'productVariant:id,variant_code', 'locationable']);

        match ($sort) {
            'quantity_asc' => $query->orderBy('quantity')->orderBy('id'),
            'quantity_desc' => $query->orderByDesc('quantity')->orderBy('id'),
            'newest' => $query->orderByDesc('updated_at')->orderByDesc('id'),
            default => $query
                ->orderByRaw(
                    'case when quantity <= 0 then 0 when (min_quantity > 0 and quantity <= min_quantity) or (min_quantity <= 0 and quantity <= ?) then 1 else 2 end',
                    [StockVisibilityService::DEFAULT_LOW_STOCK_THRESHOLD],
                )
                ->orderBy('quantity')
                ->orderBy('id'),
        };

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * The filter picker's locations: the caller's accessible, not-deleted
     * warehouses and stores, name-ordered. Scoped to the same access set as
     * the rows themselves — restricted staff only see their assigned
     * locations.
     *
     * The qualified column lists matter: restricted staff resolve through a
     * morphedByMany whose pivot also has an `id`, so a bare column list is
     * ambiguous.
     *
     * @return array{warehouses: Collection<int, Warehouse>, stores: Collection<int, Store>}
     */
    public function filterLocations(User $user): array
    {
        $warehouses = $user->accessibleWarehouses()
            ->where('status', '!=', Warehouse::STATUS_DELETED)
            ->orderBy('name')
            ->get(['warehouses.id', 'warehouse_code', 'name']);

        $stores = $user->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            ->get(['stores.id', 'store_id', 'name']);

        return ['warehouses' => $warehouses, 'stores' => $stores];
    }

    /**
     * One warehouse from the caller's accessible, not-deleted set. `$value` is
     * the legacy id-or-code filter form: a numeric value matches the key,
     * anything else the warehouse code. A value outside the set returns null —
     * the controller turns that into the 403.
     */
    public function findAccessibleWarehouse(User $user, int|string $value): ?Warehouse
    {
        $query = $user->accessibleWarehouses()
            ->where('status', '!=', Warehouse::STATUS_DELETED);

        return is_numeric($value)
            ? (clone $query)->whereKey((int) $value)->first()
            : (clone $query)->where('warehouse_code', $value)->first();
    }

    /**
     * One store from the caller's accessible, not-deleted set, matching by key
     * or by the `store_id` code exactly like findAccessibleWarehouse().
     */
    public function findAccessibleStore(User $user, int|string $value): ?Store
    {
        $query = $user->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED);

        return is_numeric($value)
            ? (clone $query)->whereKey((int) $value)->first()
            : (clone $query)->where('store_id', $value)->first();
    }

    /**
     * A product of the caller's business by id, for the product_id filter. The
     * business constraint is the tenancy scope: without it a foreign product
     * id would silently return another business's product.
     */
    public function findBusinessProduct(User $user, int $id): ?Product
    {
        return Product::query()
            ->where('business_id', $user->business_id)
            ->whereKey($id)
            ->first();
    }

    /**
     * The morphed (store/warehouse) half of the per-location access predicate:
     * whether this exact row sits inside the caller's accessible set. The
     * business half is asserted separately through TenantGuard; this is the
     * check TenantGuard's four shapes do not express, and getting it wrong
     * would leak another location's min level.
     */
    public function isAccessibleTo(User $user, StockLocation $location): bool
    {
        return $this->stock->accessibleLocationQuery($user)->whereKey($location->getKey())->exists();
    }

    /**
     * The accessible subset of a list of location ids — the all-or-nothing
     * check the bulk save runs before it writes a single row. Access is
     * re-derived from the authenticated user, never from the request payload.
     *
     * @param  array<int, int|string>  $ids
     * @return Collection<int, int|string>
     */
    public function accessibleLocationIds(User $user, array $ids): Collection
    {
        return $this->stock->accessibleLocationQuery($user)
            ->whereIn('stock_locations.id', $ids)
            ->pluck('stock_locations.id');
    }

    /**
     * The min-level write lock: the row is re-fetched under lockForUpdate()
     * inside the caller's transaction, never written from the route-bound
     * copy. findOrFail keeps the pre-refactor 404 if the row vanished between
     * binding and the lock.
     */
    public function findForUpdate(int $id): StockLocation
    {
        return StockLocation::query()->lockForUpdate()->findOrFail($id);
    }

    /**
     * The bulk counterpart: every row locked before any of them is written,
     * inside the caller's transaction.
     *
     * @param  array<int, int|string>  $ids
     * @return Collection<int, StockLocation>
     */
    public function lockByIds(array $ids): Collection
    {
        return StockLocation::query()->whereIn('id', $ids)->lockForUpdate()->get();
    }

    /**
     * The saved rows for the bulk response, with the relations the shared row
     * shape renders.
     *
     * @param  array<int, int|string>  $ids
     * @return Collection<int, StockLocation>
     */
    public function loadByIdsForDisplay(array $ids): Collection
    {
        return StockLocation::query()
            ->whereIn('id', $ids)
            ->with(['product.images', 'productVariant:id,variant_code', 'locationable'])
            ->get();
    }

    /**
     * "attention" is the union the badges and the dashboard card show: low or
     * out, never both lists drifting apart. Any other value is applied as the
     * single state it names.
     */
    private function applyStateFilter(Builder $query, string $state): void
    {
        if ($state === 'attention') {
            $query->where(function (Builder $group) {
                $group->where(function (Builder $low) {
                    $this->stock->applyLocationState($low, StockVisibilityService::STATE_LOW);
                })->orWhere(function (Builder $out) {
                    $this->stock->applyLocationState($out, StockVisibilityService::STATE_OUT);
                });
            });

            return;
        }

        $this->stock->applyLocationState($query, $state);
    }

    /**
     * The product-level counterpart of applyStateFilter(), for the fallback
     * rows.
     */
    private function applyProductStateFilter(Builder $query, string $state): void
    {
        if ($state === 'attention') {
            $query->where(function (Builder $group) {
                $group->where(function (Builder $low) {
                    $this->stock->applyProductState($low, StockVisibilityService::STATE_LOW);
                })->orWhere(function (Builder $out) {
                    $this->stock->applyProductState($out, StockVisibilityService::STATE_OUT);
                });
            });

            return;
        }

        $this->stock->applyProductState($query, $state);
    }
}
