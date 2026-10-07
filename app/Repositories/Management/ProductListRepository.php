<?php

namespace App\Repositories\Management;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * WS-25 — query building for the products list and its bulk selections.
 *
 * The list carries the filters legacy supported server-side but never exposed
 * (`from`/`to`, the 10/50/100 per-page whitelist) plus the low-stock and
 * section filters the screens need. The bulk selections read through the same
 * access scope as the list, so "what I can see" and "what I can act on" can
 * never disagree — and every read here is tenancy-scoped, where getting the
 * scope wrong leaks another business's catalogue.
 *
 * Query building and reads only: transaction boundaries and abort() calls
 * belong to ProductBulkService and the controller.
 *
 * The access scope mirrors ProductRepository's (WS-14) deliberately: a row
 * with `store_id = NULL` is manageable while the caller can reach the
 * warehouse it lives in, and fully detached rows stay reachable for anyone
 * who is not restricted staff — legacy created products without a store, and
 * those rows must stay editable.
 */
final class ProductListRepository
{
    /**
     * Legacy amber stock warning threshold on the products list.
     *
     * The low_stock flag the payload publishes is computed from ProductResource
     * with the same threshold, so the filter has to agree with it or the count
     * and the rows would disagree.
     */
    public const LOW_STOCK_THRESHOLD = 10;

    /**
     * Sort whitelist: name => [column, direction].
     *
     * Legacy always listed newest-first; the sort whitelist is a net-new
     * convenience for the bigger catalogs.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const SORTS = [
        'newest' => ['created_at', 'desc'],
        'oldest' => ['created_at', 'asc'],
        'name' => ['name', 'asc'],
        'name_desc' => ['name', 'desc'],
        'price_low' => ['amount', 'asc'],
        'price_high' => ['amount', 'desc'],
        'stock_low' => ['quantity', 'asc'],
    ];

    /**
     * Relations the list rows render.
     *
     * @var array<int, string>
     */
    private const LIST_RELATIONS = ['images', 'variants', 'currency', 'store', 'warehouse', 'category', 'section'];

    /**
     * The product list: the access scope, legacy's filters, the list eager
     * loads and the whitelisted ordering.
     *
     * `$digitalOnly`, `$hasVariants` and `$lowStock` are the request helpers'
     * resolved values, not the validated payload: a submitted `has_variants=`
     * validates as an empty string, and only the helper tells "absent" (no
     * filter) from "present but false" (filter on false).
     *
     * @param  array<string, mixed>  $filters  validated by ProductListIndexRequest
     */
    public function paginateForUser(
        User $user,
        array $filters,
        bool $digitalOnly,
        ?bool $hasVariants,
        bool $lowStock,
    ): LengthAwarePaginator {
        $query = $this->accessibleQuery($user)
            ->with(self::LIST_RELATIONS)
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->when($filters['warehouse_id'] ?? null, fn ($q, $warehouseId) => $q->where('warehouse_id', $warehouseId))
            ->when($filters['category_id'] ?? null, fn ($q, $categoryId) => $q->where('category_id', $categoryId))
            ->when($filters['section_id'] ?? null, fn ($q, $sectionId) => $q->where('section_id', $sectionId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($digitalOnly, fn ($q) => $q->where('is_digital', true))
            ->when($hasVariants !== null, fn ($q) => $q->where('has_variants', $hasVariants))
            ->when($lowStock, fn ($q) => $this->applyLowStockFilter($q))
            ->when($filters['q'] ?? null, function ($q, $term) {
                // Legacy searched the name, the code and the category name;
                // the brand column joined the list with WS-14.
                $q->where(fn ($inner) => $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('product_code', 'like', "%{$term}%")
                    ->orWhere('brand', 'like', "%{$term}%")
                    ->orWhereHas('category', fn ($category) => $category->where('name', 'like', "%{$term}%")));
            })
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()));

        $this->applySorting($query, $filters['sort'] ?? null);

        return $query->paginate($filters['per_page'] ?? 20);
    }

    /**
     * The reachable products a bulk edit will touch, in one query — anything
     * the caller cannot reach is reported back as skipped rather than silently
     * dropped. The caller keys the result by id.
     *
     * @param  Collection<int, int>  $requestedIds
     * @return EloquentCollection<int, Product>
     */
    public function productsForUpdate(User $user, Collection $requestedIds): EloquentCollection
    {
        return $this->accessibleQuery($user)->whereIn('id', $requestedIds)->get();
    }

    /**
     * The reachable ids of a bulk activate/deactivate selection. Read through
     * the access scope so the reported count is the selection's reachable
     * size, not what a mass update happened to change.
     *
     * @param  Collection<int, int>  $requestedIds
     * @return Collection<int, int>
     */
    public function reachableIds(User $user, Collection $requestedIds): Collection
    {
        return $this->accessibleQuery($user)->whereIn('id', $requestedIds)->pluck('id');
    }

    /**
     * The reachable products a bulk delete will remove, with the images and
     * digital files the cleanup walks.
     *
     * @param  Collection<int, int>  $requestedIds
     * @return EloquentCollection<int, Product>
     */
    public function productsForDelete(User $user, Collection $requestedIds): EloquentCollection
    {
        return $this->accessibleQuery($user)
            ->with(['images', 'files'])
            ->whereIn('id', $requestedIds)
            ->get();
    }

    /**
     * The low_stock flag the payload publishes is computed from the variant
     * rows for variant-driven products, so the filter has to agree with it or
     * the count and the rows would disagree.
     */
    private function applyLowStockFilter(Builder $query): void
    {
        $query->where('is_digital', false)
            ->where(function ($inner) {
                $inner->where(function ($q) {
                    $q->where('has_variants', true)
                        ->whereRaw(
                            '(select coalesce(sum(quantity), 0) from product_variants where product_variants.product_id = products.id) <= ?',
                            [self::LOW_STOCK_THRESHOLD],
                        );
                })->orWhere(function ($q) {
                    $q->where('has_variants', false)->where('quantity', '<=', self::LOW_STOCK_THRESHOLD);
                });
            });
    }

    private function applySorting(Builder $query, ?string $sort): void
    {
        [$column, $direction] = self::SORTS[$sort ?? 'newest'];

        // id tiebreak keeps pagination stable when many rows share a timestamp.
        $query->orderBy($column, $direction)->orderBy('id', $direction);
    }

    /**
     * Products the caller may manage: their stores, plus store-less rows the
     * business still holds in one of their warehouses (legacy created products
     * without a store, and those rows must stay editable), plus fully detached
     * rows for anyone who is not restricted staff.
     */
    private function accessibleQuery(User $user): Builder
    {
        $storeIds = $this->storeIds($user);
        $warehouseIds = $user->accessibleWarehouseIds();

        return Product::query()
            ->where('business_id', $user->business_id)
            ->where(function ($query) use ($user, $storeIds, $warehouseIds) {
                $query->whereIn('store_id', $storeIds)
                    ->orWhere(fn ($inner) => $inner->whereNull('store_id')->whereIn('warehouse_id', $warehouseIds));

                if (! $user->isRestrictedStaff()) {
                    $query->orWhere(fn ($inner) => $inner->whereNull('store_id')->whereNull('warehouse_id'));
                }
            });
    }

    /**
     * Accessible stores minus soft-deleted ones — legacy filtered deleted
     * stores on every product read.
     *
     * @return Collection<int, int>
     */
    private function storeIds(User $user): Collection
    {
        return $user->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            // Restricted staff read through the staff_assignments pivot, which
            // carries its own `id`; a bare pluck is then ambiguous between the
            // two tables and the query fails. Same qualification as
            // User::accessibleStoreIds().
            ->pluck('stores.id');
    }
}
