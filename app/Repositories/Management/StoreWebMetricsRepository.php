<?php

namespace App\Repositories\Management;

use App\Enums\TransactionStatus;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Support\Money\Naira;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * WS-35 — every read behind the store web-metrics payload.
 *
 * Sources are the definitions the legacy service used and the new schema
 * still carries:
 *   - product views → sum of `products.views`
 *   - web orders    → `orders.source = 'checkout'`
 *   - web revenue   → confirmed transactions on checkout orders *or* on the
 *                     store's invoices (the legacy definition; invoices are
 *                     settled off-storefront and were always counted).
 *
 * (Store views is `stores.views`, a counter incremented by the storefront, so
 * it is read straight off the store model by the controller.)
 *
 * Deliberate departures from legacy, carried with the queries:
 *   - Money is summed in SQL (the decimal naira columns) and converted once,
 *     here, into integer kobo — the wire format the rest of the accounting
 *     reads use. No float accumulation. The conversion is
 *     `Naira::koboFromRounded()`, the named contract that is exactly the
 *     `(int) round((float) $sum * 100)` this code has always run.
 *   - Every query is scoped to the store's business; the legacy service
 *     filtered invoices by store id alone.
 *
 * This layer only builds queries and aggregates: it never opens a
 * transaction, never aborts an HTTP request and never shapes a response.
 */
final class StoreWebMetricsRepository
{
    /** The source the storefront checkout stamps on orders. */
    private const WEB_ORDER_SOURCE = 'checkout';

    private const TOP_PRODUCTS_LIMIT = 10;

    private const ACTIVITY_LIMIT = 10;

    /**
     * The web-orders tile: how many checkout orders, and what they are worth
     * in kobo, within the requested window.
     *
     * @return array{count: int, value_kobo: int}
     */
    public function webOrderTotals(Store $store, ?Carbon $from, ?Carbon $to): array
    {
        $query = $this->webOrderQuery($store, $from, $to);

        return [
            'count' => $query->clone()->count(),
            // Order value in kobo: the column is decimal naira, so the exact
            // SQL sum converts once here — never accumulated in floats.
            'value_kobo' => Naira::koboFromRounded($query->clone()->sum('total')),
        ];
    }

    /**
     * The web-revenue tile: confirmed transactions on checkout orders *or* on
     * the store's invoices, within the window, in kobo.
     */
    public function webRevenueKobo(Store $store, ?Carbon $from, ?Carbon $to): int
    {
        $query = Transaction::query()
            ->where('business_id', $store->business_id)
            ->where('status', TransactionStatus::CONFIRMED)
            ->where(fn ($nested) => $nested
                ->whereHas('order', fn ($orders) => $orders
                    ->where('store_id', $store->id)
                    ->where('source', self::WEB_ORDER_SOURCE))
                ->orWhereHas('invoice', fn ($invoices) => $invoices->where('store_id', $store->id)));

        // paid_at is the honest month for a payment; fall back to created_at
        // for rows that were never stamped (same rule as WS-03's revenue).
        if ($from !== null) {
            $query->whereRaw('COALESCE(paid_at, created_at) >= ?', [$from]);
        }
        if ($to !== null) {
            $query->whereRaw('COALESCE(paid_at, created_at) <= ?', [$to]);
        }

        return Naira::koboFromRounded($query->sum('amount'));
    }

    /**
     * The product-views tile. A lifetime counter with no per-hit timestamp,
     * so the date range does not apply (the SPA labels it as all-time).
     *
     * Business- and store-scoped so a sibling store's views can never leak in.
     */
    public function productViews(Store $store): int
    {
        return (int) Product::query()
            ->where('business_id', $store->business_id)
            ->where('store_id', $store->id)
            ->sum('views');
    }

    /**
     * The chart's raw counts: one row per bucket key (`Y-m-d` for daily bars,
     * `Y-m` for monthly), grouped in SQL.
     *
     * The bucket key stays a full date string so month labels never collide
     * across a year boundary the way the legacy "May" did.
     *
     * @return Collection<string, int>
     */
    public function webOrderCountsByBucket(Store $store, Carbon $from, Carbon $to, string $bucket): Collection
    {
        $keyExpression = $bucket === 'day'
            ? "DATE_FORMAT(created_at, '%Y-%m-%d')"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        return $this->webOrderQuery($store, $from, $to)
            ->selectRaw("{$keyExpression} as bucket, COUNT(*) as count")
            ->groupBy('bucket')
            ->pluck('count', 'bucket');
    }

    /**
     * Top products by views — lifetime counters, so the date range does not
     * apply (a viewed product has no per-view timestamp to filter on).
     *
     * @return Collection<int, Product>
     */
    public function topProducts(Store $store): Collection
    {
        return Product::query()
            ->where('business_id', $store->business_id)
            ->where('store_id', $store->id)
            ->orderByDesc('views')
            ->orderBy('name')
            ->limit(self::TOP_PRODUCTS_LIMIT)
            ->get(['id', 'product_code', 'name', 'views', 'status', 'is_digital']);
    }

    /**
     * The store's own activity feed: log rows written against this store
     * (storefront visits, store edits), newest first. Scoped to the business
     * as well as the subject so a cross-tenant subject id could never surface.
     *
     * @return Collection<int, ActivityLog>
     */
    public function recentActivity(Store $store): Collection
    {
        return ActivityLog::query()
            ->where('business_id', $store->business_id)
            ->where('subject_type', Store::class)
            ->where('subject_id', $store->id)
            ->with('user:id,name')
            ->latest()
            ->limit(self::ACTIVITY_LIMIT)
            ->get();
    }

    /**
     * Checkout orders in one store, optionally within the requested window.
     * Both the tile and the chart read through this scope.
     */
    private function webOrderQuery(Store $store, ?Carbon $from, ?Carbon $to): Builder
    {
        return Order::query()
            ->where('business_id', $store->business_id)
            ->where('store_id', $store->id)
            ->where('source', self::WEB_ORDER_SOURCE)
            ->when($from !== null, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('created_at', '<=', $to));
    }
}
