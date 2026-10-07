<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\DashboardParityRequest;
use App\Http\Resources\Admin\Dashboard\ChartSeriesResource;
use App\Http\Resources\Admin\Dashboard\PaymentBreakdownResource;
use App\Http\Resources\Admin\Dashboard\PendingTransferResource;
use App\Http\Resources\Admin\Dashboard\RecentBusinessResource;
use App\Http\Resources\Admin\Dashboard\RecentOrderResource;
use App\Http\Resources\Admin\Dashboard\RecentTransactionResource;
use App\Http\Resources\Admin\Dashboard\StoreOptionResource;
use App\Http\Resources\Admin\Dashboard\TopStoreResource;
use App\Repositories\Admin\DashboardParityRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * WS7 — Dashboard parity completion (admin console).
 *
 * The legacy `/office/dashboard` was a single heavy page backed by
 * `Admin\AdminDashboardController@index`; the rewrite kept two charts and a
 * re-scoped store table and dropped the rest. This controller restores the
 * legacy read-outs as the *superset* of what the old endpoint returned, so the
 * existing consumers (`AdminApiTest`, the sidebar badge) keep working:
 *
 *  - KPI row: today's revenue, today's orders, MTD revenue, stock value,
 *    active stores, customers.
 *  - Stats grid: total revenue/transactions, orders (+ pending), businesses,
 *    stores (+ warehouses), products (+ in stock), low/out-of-stock, staff
 *    (+ open POS sessions), KYC pending.
 *  - Charts: daily revenue and daily orders over the 7/30/90-day window,
 *    payment-method donut scoped by `from`/`to`, and the monthly
 *    `revenue_series`/`orders_series` the previous payload shipped but never
 *    rendered.
 *  - Panels: every non-deleted store with today/MTD pulse, pending transfers
 *    (+ dispatch/receive counters), latest confirmed transactions, latest
 *    orders and recent businesses.
 *
 * Store filter scope follows verify correction C1 exactly: only the four
 * legacy-scoped KPI tiles (revenue today, orders today, revenue MTD, stock
 * value), stock units/low/out-of-stock counts, both daily charts, the payment
 * donut, the pending-transfers list and the store table honour `store_id`.
 * The businesses/stores/customers counts, the whole stats grid, the monthly
 * series and the recent transactions/orders feeds are platform-wide, exactly
 * as legacy computed them.
 *
 * Improvements over legacy (audit's "improve on legacy", not optional):
 *  - The low-stock band is back to `qty > 0 && qty <= 10` and is configurable
 *    via `low_stock_threshold` (the previous endpoint silently used 1–5).
 *  - `days` and `low_stock_threshold` are validated rather than coerced.
 *  - An unknown store filter (or a non-scalar `store_id`) is a 422 rather than
 *    a silently-empty dashboard.
 *  - Per-store `last_order_at` is a correlated sub-select, not the legacy
 *    N+1 `orders()->latest()->value()` per row.
 *  - The daily series are grouped in SQL and zero-filled, not 30–90 queries.
 *
 * Money is summed in SQL (`decimal` columns); no PHP float arithmetic, and no
 * legacy naming anywhere.
 *
 * The layer split: filter validation lives in `DashboardParityRequest`, every
 * read and its aggregates in `DashboardParityRepository`, row shaping in
 * `App\Http\Resources\Admin\Dashboard\*`, and the chart zero-fill in
 * `ChartSeriesResource`. The controller keeps the HTTP shape only — status
 * codes, message strings, the envelope — plus the two 422 guards whose
 * messages and order the tests pin (the unknown store and the from/to
 * ordering). No service was extracted: this endpoint is read-only — no
 * transaction, no second-table workflow, no ledger/mail/notification.
 */
class DashboardParityController extends ApiController
{
    /**
     * Legacy flagged `quantity > 0 && quantity <= 10` as low stock before the
     * bandwidth was silently narrowed to 1–5. The window itself (7/30/90) and
     * the band's ceiling live on the request that validates them.
     */
    private const DEFAULT_LOW_STOCK_THRESHOLD = 10;

    public function __construct(
        private readonly DashboardParityRepository $dashboard,
    ) {}

    public function index(DashboardParityRequest $request): JsonResponse
    {
        $days = (int) ($request->validated('days') ?? 30);
        $lowStockThreshold = (int) ($request->validated('low_stock_threshold') ?? self::DEFAULT_LOW_STOCK_THRESHOLD);

        $storeFilter = null;
        $rawStoreId = $request->validated('store_id');

        if ($rawStoreId !== null && $rawStoreId !== '') {
            $storeFilter = $this->dashboard->resolveStoreFilter($rawStoreId);

            if (! $storeFilter) {
                return $this->error('The selected store does not exist.', 422, [
                    'store_id' => ['The selected store does not exist.'],
                ]);
            }
        }

        $storeId = $storeFilter?->id;

        // Legacy defaults: start of month → now. The range only ever scoped
        // the payment-method donut, which is why it stays separate from the
        // range pills that drive the daily charts.
        $fromValue = $request->validated('from');
        $toValue = $request->validated('to');

        $from = $fromValue !== null ? Carbon::parse($fromValue)->startOfDay() : now()->startOfMonth();
        $to = $toValue !== null ? Carbon::parse($toValue)->endOfDay() : now();

        if ($from->gt($to)) {
            return $this->error('The to date must be after or equal to the from date.', 422, [
                'to' => ['The to date must be after or equal to the from date.'],
            ]);
        }

        $stock = $this->dashboard->stockMetrics($storeId, $lowStockThreshold);

        $dailyRevenue = ChartSeriesResource::daily(
            $this->dashboard->dailyRevenueTotals($storeId, $days),
            $days,
            true,
        );

        $dailyOrders = ChartSeriesResource::daily(
            $this->dashboard->dailyOrderTotals($storeId, $days),
            $days,
            false,
        );

        return $this->ok([
            'range_days' => $days,
            'filters' => [
                'store_id' => $storeFilter?->id,
                'store_code' => $storeFilter?->store_id,
                'store_name' => $storeFilter?->name,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $days,
                'low_stock_threshold' => $lowStockThreshold,
            ],
            'stores' => StoreOptionResource::collection($this->dashboard->storeOptions())->resolve(),
            'kpis' => $this->dashboard->kpiRow($storeId, $stock['stock_value']),
            'stats' => $this->dashboard->statsGrid($stock, $lowStockThreshold),
            'daily_revenue' => $dailyRevenue,
            'daily_orders' => $dailyOrders,
            'payment_breakdown' => PaymentBreakdownResource::collection($this->dashboard->paymentBreakdown($from, $to, $storeId))->resolve(),
            'top_stores' => TopStoreResource::collection($this->dashboard->topStores($storeId))->resolve(),
            'pending_transfers' => PendingTransferResource::collection($this->dashboard->pendingTransfers($storeId))->resolve(),
            'transfer_stats' => $this->dashboard->transferStats(),
            'recent_transactions' => RecentTransactionResource::collection($this->dashboard->recentTransactions())->resolve(),
            'recent_orders' => RecentOrderResource::collection($this->dashboard->recentOrders())->resolve(),
            'recent_businesses' => RecentBusinessResource::collection($this->dashboard->recentBusinesses())->resolve(),
            // The previous payload shipped these two monthly series but no
            // screen rendered them; the dashboard now charts them (they had no
            // legacy counterpart and stay platform-wide).
            'revenue_series' => ChartSeriesResource::monthly($this->dashboard->monthlyRevenueTotals()),
            'orders_series' => ChartSeriesResource::monthly($this->dashboard->monthlyOrderTotals()),
        ]);
    }
}
