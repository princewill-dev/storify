<?php

namespace App\Repositories\Management\Warehouse;

use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Query building for the warehouse surface.
 *
 * `paginateForUser()` earns its place twice over: it is tenancy-scoped (a
 * wrong scope leaks another business's warehouses) and it is a real
 * composition — the section/staff counts, the stock sum, the low-stock
 * aggregate, the filters, the ordering and the paging. `userCanAccessWarehouse()`
 * is the same access scope for the `{warehouse}` in the URL, so the list and
 * the URL guard can never drift apart. `recentMovements()` is the
 * eager-loaded, ordered, limited read behind the detail page's activity list,
 * and the two `load*()` methods are the load contracts the detail and show
 * payloads depend on.
 *
 * Reads and query building only: no transactions, no abort() — the refusals
 * (the URL warehouse's 403, the delete-with-stock 422) stay in the controller
 * so their place in the refusal order is unchanged.
 */
final class WarehouseRepository
{
    /**
     * The warehouse list for the caller: their accessible warehouses minus
     * soft-deleted rows, the aggregates each row renders, the `q`/`status`
     * filters and the name ordering, paginated.
     *
     * @param  array<string, mixed>  $filters  validated by WarehouseIndexRequest
     */
    public function paginateForUser(User $user, array $filters): LengthAwarePaginator
    {
        return $this->accessibleQuery($user)
            ->withCount(['sections', 'assignedStaff'])
            ->withSum('stockLocations as total_stock', 'quantity')
            ->withCount(['stockLocations as product_count'])
            ->withCount(['stockLocations as low_stock_count' => fn ($q) => $q->lowStock()])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * Tenancy check for the `{warehouse}` in the URL — the controller's
     * private `accessibleQuery()->whereKey()->exists()` shape, moved verbatim.
     * The 403 stays with the controller.
     */
    public function userCanAccessWarehouse(User $user, Warehouse $warehouse): bool
    {
        return $this->accessibleQuery($user)
            ->whereKey($warehouse->getKey())
            ->exists();
    }

    /**
     * The show page's eager loads, exactly the controller's `load([...])`
     * call: `stockLocations.product` feeds the stats and the movements list,
     * `sections` and `assignedStaff` feed the detail payload.
     */
    public function loadForShow(Warehouse $warehouse): Warehouse
    {
        $warehouse->load(['stockLocations.product', 'sections', 'assignedStaff']);

        return $warehouse;
    }

    /**
     * The detail payload's loads, exactly the controller's
     * `loadMissing(['sections', 'assignedStaff'])`: the two relations every
     * create/update response renders. `loadMissing` keeps the show page's
     * already-loaded pair from a second query.
     */
    public function loadForDetail(Warehouse $warehouse): Warehouse
    {
        $warehouse->loadMissing(['sections', 'assignedStaff']);

        return $warehouse;
    }

    /**
     * The warehouse's latest 20 stock movements — the legacy Activity tab's
     * shape. The location ids come from the warehouse's own loaded stock
     * locations, so the tenancy decision was already made by the caller's
     * guard.
     *
     * @return EloquentCollection<int, StockMovement>
     */
    public function recentMovements(Warehouse $warehouse): EloquentCollection
    {
        return StockMovement::whereIn('stock_location_id', $warehouse->stockLocations->pluck('id'))
            ->with(['product', 'performedBy'])
            ->latest()
            ->limit(20)
            ->get();
    }

    /**
     * The access scope every warehouse read shares: the user's accessible
     * warehouses (owned, staff-wide, assigned or platform-admin, per the
     * model) minus soft-deleted rows. The column is qualified through the
     * query's own model because the restricted-staff branch joins the
     * `staff_assignments` pivot.
     *
     * @return Builder|HasMany|MorphToMany
     */
    private function accessibleQuery(User $user)
    {
        $query = $user->accessibleWarehouses();

        return $query->where(
            $query->getModel()->qualifyColumn('status'),
            '!=',
            Warehouse::STATUS_DELETED,
        );
    }
}
