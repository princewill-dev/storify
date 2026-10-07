<?php

namespace App\Repositories\Management;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * WS-29 — reads behind the stock-movement ledger.
 *
 * The scoped query (business, then the assigned-location restriction for
 * restricted staff), the list filters/eager loads/ordering/pagination, the
 * filter-picker location options and the tenancy-scoped finders behind the
 * warehouse_id / store_id / product_id guards live here.
 *
 * Query building only: nothing in this layer aborts an HTTP request. The
 * finders return null for a value outside the user's circle and the controller
 * turns that into the 403, exactly where the pre-refactor body did.
 */
final class StockMovementRepository
{
    /**
     * Movements are business-scoped and then narrowed to the locations the
     * user can actually see — a restricted staff member's history stops at
     * their assigned stores and warehouses.
     *
     * @return Builder<StockMovement>
     */
    public function scopedQuery(User $user): Builder
    {
        $storeIds = $user->accessibleStoreIds();
        $warehouseIds = $user->accessibleWarehouseIds();

        return StockMovement::query()
            ->where('business_id', $user->business_id)
            ->whereHas('stockLocation', function (Builder $query) use ($storeIds, $warehouseIds) {
                $query->where(function (Builder $hosts) use ($storeIds, $warehouseIds) {
                    $hosts->where(function (Builder $stores) use ($storeIds) {
                        $stores->where('locationable_type', Store::class)->whereIn('locationable_id', $storeIds);
                    })->orWhere(function (Builder $warehouses) use ($warehouseIds) {
                        $warehouses->where('locationable_type', Warehouse::class)->whereIn('locationable_id', $warehouseIds);
                    });
                });
            });
    }

    /**
     * The list: the warehouse/store/location/product/type/search/date filters,
     * eager loads, sort and pagination. The warehouse, store and product
     * filters arrive already resolved and access-checked by the controller —
     * a foreign id aborts 403 there, so this method never runs for one.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<StockMovement>
     */
    public function paginateForUser(
        User $user,
        array $filters,
        ?Warehouse $warehouse = null,
        ?Store $store = null,
        ?Product $product = null,
    ): LengthAwarePaginator {
        $query = $this->scopedQuery($user)
            ->when($warehouse, fn (Builder $inner, Warehouse $filter) => $inner->whereHas('stockLocation', fn (Builder $location) => $location
                ->where('locationable_type', Warehouse::class)
                ->where('locationable_id', $filter->id)))
            ->when($store, fn (Builder $inner, Store $filter) => $inner->whereHas('stockLocation', fn (Builder $location) => $location
                ->where('locationable_type', Store::class)
                ->where('locationable_id', $filter->id)))
            ->when(
                $filters['stock_location_id'] ?? null,
                fn (Builder $inner, $locationId) => $inner->where('stock_location_id', (int) $locationId),
            )
            ->when($product, fn (Builder $inner, Product $filter) => $inner->where('product_id', $filter->id))
            ->when($filters['type'] ?? null, fn (Builder $inner, $type) => $inner->where('type', $type))
            ->when($filters['q'] ?? null, function (Builder $inner, string $term) {
                $inner->where(function (Builder $search) use ($term) {
                    $search->where('movement_code', 'like', "%{$term}%")
                        ->orWhere('notes', 'like', "%{$term}%")
                        ->orWhereHas('product', fn (Builder $product) => $product
                            ->where('name', 'like', "%{$term}%")
                            ->orWhere('product_code', 'like', "%{$term}%"));
                });
            })
            ->when($filters['from'] ?? null, fn (Builder $inner, $from) => $inner->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $inner, $to) => $inner->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->with([
                'product.images',
                'productVariant:id,variant_code',
                'stockLocation.locationable',
                'fromLocation',
                'toLocation',
                'performedBy',
                'reference',
            ]);

        $direction = ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc';

        return $query
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();
    }

    /**
     * The filter picker's locations: the user's accessible, not-deleted
     * warehouses and stores, name-ordered.
     *
     * The qualified column lists matter — restricted staff resolve through a
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
     * One warehouse from the user's accessible, not-deleted set. `$value` is
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
     * One store from the user's accessible, not-deleted set, matching by key
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
     * A product of the user's business by id, for the product_id filter. The
     * business constraint is the tenancy scope: without it a foreign product
     * id would silently return another business's history.
     */
    public function findBusinessProduct(User $user, int $id): ?Product
    {
        return Product::query()
            ->where('business_id', $user->business_id)
            ->whereKey($id)
            ->first();
    }
}
