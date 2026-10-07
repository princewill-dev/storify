<?php

namespace App\Repositories\Management\Section;

use App\Models\Product;
use App\Models\Section;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * WS-36 — query building for the sections surface.
 *
 * Scoping, filters, eager loads, the metric-card aggregates and the
 * access-scoped reads live here; reads and query building only — transaction
 * boundaries and abort() calls belong to
 * App\Services\Management\Section\SectionService and the controller.
 *
 * The warehouse and store scopes are the controller's private checks, moved
 * verbatim (qualification included), because a wrong scope here leaks or
 * hides another tenant's sections.
 */
final class SectionRepository
{
    /**
     * The sections list of one warehouse: non-deleted rows, the product
     * counts and the name ordering the controller used.
     *
     * @param  array<string, mixed>  $filters  validated by SectionIndexRequest
     */
    public function paginateForWarehouse(Warehouse $warehouse, array $filters): LengthAwarePaginator
    {
        return $this->queryForWarehouse($warehouse)
            ->withCount('products')
            ->withCount(['products as active_products_count' => fn ($q) => $q->where('status', 'active')])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 25);
    }

    /**
     * The header counts. They are computed unfiltered so the header keeps
     * describing the warehouse, not the current search (the controller's
     * comment, moved with the code).
     *
     * @return array{total: int, active: int, inactive: int}
     */
    public function countsForWarehouse(Warehouse $warehouse): array
    {
        return [
            'total' => $this->queryForWarehouse($warehouse)->count(),
            'active' => $this->queryForWarehouse($warehouse)->where('status', Section::STATUS_ACTIVE)->count(),
            'inactive' => $this->queryForWarehouse($warehouse)->where('status', Section::STATUS_INACTIVE)->count(),
        ];
    }

    /**
     * The section's products list. Legacy paginated it at 50/page; `images`
     * is eager-loaded because every row renders its primary image.
     *
     * @param  array<string, mixed>  $filters  validated by SectionProductsRequest
     */
    public function paginateProducts(Section $section, array $filters): LengthAwarePaginator
    {
        return $section->products()
            ->with(['store', 'images'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($inner) => $inner->where('name', 'like', "%{$term}%")
                ->orWhere('product_code', 'like', "%{$term}%")))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 50);
    }

    /**
     * The five legacy metric cards' aggregates, in the controller's order.
     * `amount_total` stays the raw decimal-column SUM: the stats resource
     * converts it to kobo without float arithmetic.
     *
     * @return array{amount_total: mixed, products_count: int, active_products_count: int, stock_count: int, out_of_stock_count: int}
     */
    public function statsForSection(Section $section): array
    {
        return [
            'amount_total' => $section->products()->sum('amount'),
            'products_count' => $section->products()->count(),
            'active_products_count' => $section->products()->where('status', 'active')->count(),
            'stock_count' => (int) $section->products()->sum('quantity'),
            'out_of_stock_count' => $section->products()->where('quantity', '<=', 0)->count(),
        ];
    }

    /**
     * Picker source for the product form and the assign-products modal:
     * every non-deleted section the caller can reach, optionally narrowed to
     * one warehouse.
     *
     * @param  array<string, mixed>  $filters  validated by SectionPickerRequest
     * @return EloquentCollection<int, Section>
     */
    public function sectionsForPicker(User $user, array $filters): EloquentCollection
    {
        return Section::query()
            ->where('business_id', $user->business_id)
            ->whereIn('warehouse_id', $this->accessibleWarehouseIds($user))
            ->notDeleted()
            ->with('warehouse:id,warehouse_code,name')
            ->when($filters['warehouse_id'] ?? null, fn ($q, $warehouseId) => $q->where('warehouse_id', $warehouseId))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name')
            ->get();
    }

    /**
     * Products that may be filed into this section of this warehouse: what
     * the warehouse holds, plus warehouse-less rows reachable through the
     * caller's stores (the product form lets those inherit a warehouse from
     * the section they are given), minus what is already in the section.
     *
     * @param  array<string, mixed>  $filters  validated by SectionAvailableProductsRequest
     */
    public function paginateAvailableProducts(User $user, Warehouse $warehouse, Section $section, array $filters): LengthAwarePaginator
    {
        return $this->assignableQuery($user, $warehouse)
            ->where(fn ($q) => $q->whereNull('section_id')->orWhere('section_id', '!=', $section->id))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($inner) => $inner->where('name', 'like', "%{$term}%")
                ->orWhere('product_code', 'like', "%{$term}%")))
            ->with(['store', 'section', 'images'])
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 25);
    }

    /**
     * The assignment's business-scoped candidate read, verbatim. The caller
     * compares its count against the requested ids and answers its own 422 —
     * that refusal is HTTP shape, not a repository concern.
     *
     * @param  array<int, int>  $ids
     * @return EloquentCollection<int, Product>
     */
    public function productsForAssignment(User $user, array $ids): EloquentCollection
    {
        return Product::query()
            ->where('business_id', $user->business_id)
            ->whereIn('id', $ids)
            ->get();
    }

    /**
     * Tenancy check for the `{warehouse}` in the URL — the shape the
     * controller's `accessibleWarehouseIdExists()` encoded. The 403 stays
     * with the controller.
     */
    public function userCanAccessWarehouse(User $user, int $warehouseId): bool
    {
        return $user->accessibleWarehouses()
            ->where('warehouses.status', '!=', Warehouse::STATUS_DELETED)
            ->whereKey($warehouseId)
            ->exists();
    }

    /**
     * The detail payload's aggregate loads: the warehouse and the two product
     * counts the summary reads. Called wherever a detail payload is rendered.
     */
    public function loadForDetail(Section $section): Section
    {
        $section->loadMissing('warehouse');
        $section->loadCount(['products', 'products as active_products_count' => fn ($q) => $q->where('status', 'active')]);

        return $section;
    }

    /**
     * Sections of a warehouse that have not been soft-deleted. Every read of
     * the section collection goes through here — deleted sections stay
     * deleted (see the controller's class docblock).
     */
    private function queryForWarehouse(Warehouse $warehouse): Builder
    {
        return Section::query()
            ->where('warehouse_id', $warehouse->id)
            ->notDeleted();
    }

    /**
     * Products that can be filed into a section of this warehouse: those the
     * warehouse already holds, plus warehouse-less rows reachable through the
     * caller's stores.
     *
     * The `pluck('id')` is moved verbatim, unqualified, exactly as the
     * controller wrote it; the accessible-stores branch for restricted staff
     * joins the `staff_assignments` pivot, so a pass that needs to prove that
     * branch qualifies it (as the colocated repositories spell `stores.id`).
     */
    private function assignableQuery(User $user, Warehouse $warehouse): Builder
    {
        $storeIds = $user->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->pluck('id');

        return Product::query()
            ->where('business_id', $user->business_id)
            ->where(fn ($q) => $q->where('warehouse_id', $warehouse->id)
                ->orWhere(fn ($inner) => $inner->whereNull('warehouse_id')->whereIn('store_id', $storeIds)));
    }

    /**
     * Accessible warehouses minus soft-deleted ones, for the picker's
     * `warehouse_id` scope. The column is qualified because a restricted
     * staff member's branch is the `staff_assignments` pivot join, which also
     * carries an `id`.
     *
     * @return Collection<int, int>
     */
    private function accessibleWarehouseIds(User $user): Collection
    {
        return $user->accessibleWarehouses()
            ->where('warehouses.status', '!=', Warehouse::STATUS_DELETED)
            ->pluck('warehouses.id');
    }
}
