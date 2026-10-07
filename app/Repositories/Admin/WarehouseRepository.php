<?php

namespace App\Repositories\Admin;

use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * AD-14 — the platform warehouse oversight reads (WS14).
 *
 * The directory, its per-status totals, the detail's aggregate loads and the
 * recent-movement query behind the admin screens. Platform-scoped: the console
 * sees every business's warehouses, so nothing here carries a tenant
 * predicate; the platform-role guard on the controller is what limits access.
 *
 * Follows the repository rules: query building and eager loads only, no
 * transactions, no abort(), and no request-supplied column reaches `orderBy`
 * without the request's whitelist.
 */
final class WarehouseRepository
{
    /**
     * Legacy flagged `quantity > 0 && quantity <= 10` as low stock before the
     * new stack silently narrowed the band; WS-7 restored the same threshold.
     *
     * It lives here because it defines the query band used by both the
     * directory's `withCount` and the detail's `loadCount`; the detail payload
     * reports this same constant as `low_stock_threshold`, so the number shown
     * and the number counted cannot drift apart.
     */
    public const LOW_STOCK_THRESHOLD = 10;

    /**
     * The directory page size the pre-refactor controller used.
     */
    private const PER_PAGE = 15;

    /**
     * The platform directory: every business, deleted rows excluded by default
     * (but reachable through the `deleted` status filter), `q` over the name,
     * code or owning business, with the per-row stock totals the management
     * grid shows instead of a bare stock-location count.
     *
     * @param  array{status?: string|null, q?: string|null, per_page?: int|null, sort?: string|null, direction?: string|null}  $filters
     * @return LengthAwarePaginator<Warehouse>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        return Warehouse::query()
            ->with(['business:id,name,business_code', 'user:id,name,email'])
            ->withCount(['stockLocations', 'sections'])
            ->withCount(['stockLocations as low_stock_count' => fn ($stock) => $stock
                ->where('quantity', '>', 0)
                ->where('quantity', '<=', self::LOW_STOCK_THRESHOLD)])
            ->withSum('stockLocations as total_stock', 'quantity')
            ->when(
                $filters['status'] ?? null,
                fn ($q, $status) => $q->where('status', $status),
                fn ($q) => $q->where('status', '!=', Warehouse::STATUS_DELETED),
            )
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', "%{$term}%")
                        ->orWhere('warehouse_code', 'like', "%{$term}%")
                        ->orWhereHas('business', fn ($business) => $business->where('name', 'like', "%{$term}%"));
                });
            })
            ->orderBy($filters['sort'] ?? 'name', $filters['direction'] ?? 'asc')
            ->orderBy('id', $filters['direction'] ?? 'asc')
            ->paginate($filters['per_page'] ?? self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * Per-status counts for the directory stats. Never narrowed by the active
     * filter/search, so the totals stay stable while a filter is applied.
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        return Warehouse::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * The detail's aggregate loads.
     *
     * Counts and sums mirror the directory's `withCount`/`withSum` shape so
     * both screens read the same numbers; the sections collection is the only
     * extra load the detail needs.
     */
    public function loadForDetail(Warehouse $warehouse): Warehouse
    {
        return $warehouse->loadCount(['stockLocations', 'sections'])
            ->loadCount(['stockLocations as low_stock_count' => fn ($stock) => $stock
                ->where('quantity', '>', 0)
                ->where('quantity', '<=', self::LOW_STOCK_THRESHOLD)])
            ->loadSum('stockLocations as total_stock', 'quantity')
            ->load([
                'business:id,name,business_code',
                'user:id,name,email',
                'sections' => fn ($query) => $query->withCount('products')->orderBy('name'),
            ]);
    }

    /**
     * The last 15 movements touching this warehouse on either side, with the
     * direction resolved by the payload resource so the SPA can sign and
     * colour the quantity.
     *
     * @return Collection<int, StockMovement>
     */
    public function recentMovements(Warehouse $warehouse): Collection
    {
        return StockMovement::query()
            ->where(function ($query) use ($warehouse) {
                $query->where(fn ($from) => $from
                    ->where('from_location_type', Warehouse::class)
                    ->where('from_location_id', $warehouse->id))
                    ->orWhere(fn ($to) => $to
                        ->where('to_location_type', Warehouse::class)
                        ->where('to_location_id', $warehouse->id));
            })
            ->with(['product:id,name', 'performedBy:id,name'])
            ->latest('id')
            ->limit(15)
            ->get()
            ->values();
    }
}
