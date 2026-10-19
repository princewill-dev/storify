<?php

namespace App\Repositories\Management;

use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockVisibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * WS-59 — the reads behind the inventory overview that WS-29 does not already
 * provide.
 *
 * The static totals (stock value, units, low/out counts, per-warehouse rows,
 * the low-stock list) are deliberately not re-derived here: they come from
 * `StockVisibilityService::metrics()`, the one definition Low Stock and
 * Warehouses publish. This repository adds only the three things that are
 * genuinely new:
 *
 *   - movement activity, from the stock-movements ledger;
 *   - the most valuable products;
 *   - the value split by category.
 *
 * Every query starts from `accessibleLocationQuery()`, so business scope,
 * assigned-location restrictions, deleted hosts and (for the value queries)
 * digital exclusion cannot drift apart between the tiles, the chart and the
 * breakdowns.
 *
 * Transfers are excluded from the in/out series on purpose: a transfer writes
 * two ledger rows, one on each side of the move, so summing both sides would
 * report one relocation as both stock in and stock out. The transfer pipeline
 * gets its own block.
 *
 * Query building only: nothing here aborts an HTTP request.
 */
final class InventoryOverviewRepository
{
    public function __construct(private readonly StockVisibilityService $visibility) {}

    /**
     * One row per day that had a movement, keyed by `Y-m-d`. Missing days are
     * the resource's job to zero-fill — a GROUP BY returns no row for a quiet
     * day, and a chart that closes that gap draws a line through a day on
     * which nothing happened.
     *
     * @return Collection<string, object{day: string, stock_in: int|string, stock_out: int|string, movement_count: int|string}>
     */
    public function movementSeries(
        User $user,
        ?int $storeId,
        ?int $warehouseId,
        Carbon $from,
        Carbon $to,
    ): Collection {
        return $this->movementQuery($user, $storeId, $warehouseId, $from, $to)
            ->selectRaw('DATE(created_at) as day')
            ->selectRaw('SUM(CASE WHEN type = ? THEN quantity ELSE 0 END) as stock_in', [StockMovement::TYPE_ADDED])
            ->selectRaw('SUM(CASE WHEN type = ? THEN quantity ELSE 0 END) as stock_out', [StockMovement::TYPE_REMOVED])
            ->selectRaw('COUNT(*) as movement_count')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');
    }

    /**
     * The same window summed to three figures, for the delta against the
     * previous window.
     *
     * @return array{in: int, out: int, count: int}
     */
    public function movementTotals(
        User $user,
        ?int $storeId,
        ?int $warehouseId,
        Carbon $from,
        Carbon $to,
    ): array {
        $row = $this->movementQuery($user, $storeId, $warehouseId, $from, $to)
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE 0 END), 0) as stock_in', [StockMovement::TYPE_ADDED])
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE 0 END), 0) as stock_out', [StockMovement::TYPE_REMOVED])
            ->selectRaw('COUNT(*) as movement_count')
            ->first();

        return [
            'in' => (int) ($row->stock_in ?? 0),
            'out' => (int) ($row->stock_out ?? 0),
            'count' => (int) ($row->movement_count ?? 0),
        ];
    }

    /**
     * The five products holding the most value right now.
     *
     * The kobo expression is byte-for-byte the one
     * `StockVisibilityService::metrics()` uses, so these rows reconcile with
     * the stock-value tile: variant price first, then product price, then cost.
     * Rows at or below zero quantity are left out — they are not stock on hand
     * and would rank a negative holding as lightly as a zero one.
     *
     * @return Collection<int, object>
     */
    public function topProductsByValue(
        User $user,
        ?int $storeId,
        ?int $warehouseId,
        int $limit = 5,
    ): Collection {
        return $this->valueQuery($user, $storeId, $warehouseId)
            ->selectRaw('stock_locations.product_id, products.name, products.product_code')
            ->selectRaw('SUM(stock_locations.quantity) as units')
            ->selectRaw('SUM(stock_locations.quantity * ROUND(COALESCE(product_variants.amount, products.amount, products.cost_price, 0) * 100)) as value_kobo')
            ->groupBy('stock_locations.product_id', 'products.name', 'products.product_code')
            ->orderByDesc('value_kobo')
            ->orderBy('products.name')
            ->limit($limit)
            ->get();
    }

    /**
     * The value split across product categories, largest first. A product with
     * no category is reported honestly as "Uncategorised" rather than dropped:
     * dropping it would make the parts stop adding up to the stock-value tile.
     *
     * @return Collection<int, object>
     */
    public function valueByCategory(
        User $user,
        ?int $storeId,
        ?int $warehouseId,
        int $limit = 5,
    ): Collection {
        $label = "COALESCE(categories.name, 'Uncategorised')";

        return $this->valueQuery($user, $storeId, $warehouseId)
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw("{$label} as name")
            ->selectRaw('SUM(stock_locations.quantity) as units')
            ->selectRaw('SUM(stock_locations.quantity * ROUND(COALESCE(product_variants.amount, products.amount, products.cost_price, 0) * 100)) as value_kobo')
            ->groupByRaw($label)
            ->orderByDesc('value_kobo')
            ->limit($limit)
            ->get();
    }

    /**
     * Movements in the window, scoped to the locations the user can see and to
     * the two types that represent real stock entering and leaving the
     * business.
     *
     * @return Builder<StockMovement>
     */
    private function movementQuery(
        User $user,
        ?int $storeId,
        ?int $warehouseId,
        Carbon $from,
        Carbon $to,
    ): Builder {
        $locationIds = $this->visibility
            ->accessibleLocationQuery($user, $storeId, $warehouseId)
            ->select('stock_locations.id');

        return StockMovement::query()
            ->where('business_id', $user->business_id)
            ->whereIn('stock_location_id', $locationIds)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('type', [StockMovement::TYPE_ADDED, StockMovement::TYPE_REMOVED]);
    }

    /**
     * The location/value joins shared by the two money breakdowns: physical
     * stock only, positive quantities only, variant price before product price
     * before cost.
     *
     * @return Builder<StockLocation>
     */
    private function valueQuery(User $user, ?int $storeId, ?int $warehouseId): Builder
    {
        return $this->visibility
            ->accessibleLocationQuery($user, $storeId, $warehouseId)
            ->whereHas('product', fn (Builder $query) => $query->where('is_digital', false))
            ->leftJoin('products', 'products.id', '=', 'stock_locations.product_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'stock_locations.product_variant_id')
            ->where('stock_locations.quantity', '>', 0);
    }
}
