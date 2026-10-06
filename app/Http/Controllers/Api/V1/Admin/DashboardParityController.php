<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransferStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Business;
use App\Models\Customer;
use App\Models\KycApplication;
use App\Models\Order;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

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
 * `vendor` naming anywhere.
 */
class DashboardParityController extends ApiController
{
    /**
     * The new-stack range pills. Legacy hard-coded a 30-day window.
     */
    private const DAY_WINDOWS = [7, 30, 90];

    /**
     * Legacy flagged `quantity > 0 && quantity <= 10` as low stock before the
     * bandwidth was silently narrowed to 1–5.
     */
    private const DEFAULT_LOW_STOCK_THRESHOLD = 10;

    private const MAX_LOW_STOCK_THRESHOLD = 100;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'days' => ['nullable', 'integer', Rule::in(self::DAY_WINDOWS)],
            'low_stock_threshold' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LOW_STOCK_THRESHOLD],
            // A scalar only: `?store_id[]=` must be a 422, not a cast warning.
            'store_id' => ['nullable', 'string'],
        ]);

        $days = (int) ($validated['days'] ?? 30);
        $lowStockThreshold = (int) ($validated['low_stock_threshold'] ?? self::DEFAULT_LOW_STOCK_THRESHOLD);

        $storeFilter = null;
        $rawStoreId = $validated['store_id'] ?? null;

        if ($rawStoreId !== null && $rawStoreId !== '') {
            $storeQuery = Store::query()->where('status', '!=', Store::STATUS_DELETED);

            $storeFilter = is_numeric($rawStoreId)
                ? $storeQuery->where('id', (int) $rawStoreId)->first()
                : $storeQuery->where('store_id', (string) $rawStoreId)->first();

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
        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : now();

        if ($from->gt($to)) {
            return $this->error('The to date must be after or equal to the from date.', 422, [
                'to' => ['The to date must be after or equal to the from date.'],
            ]);
        }

        $startOfMonth = now()->startOfMonth();
        $today = now()->toDateString();

        // Each closure builds a fresh base query so the store filter is applied
        // consistently wherever legacy applied it.
        $transactionQuery = fn () => Transaction::query()
            ->where('transactions.status', TransactionStatus::CONFIRMED)
            ->when($storeId, fn ($query) => $query->whereHas('order', fn ($order) => $order->where('store_id', $storeId)));

        $orderQuery = fn () => Order::query()->when($storeId, fn ($query) => $query->where('store_id', $storeId));

        // Legacy stock value/counts read stock_locations joined to active
        // products with stock on hand; a store filter narrows the location.
        $stockLocationQuery = fn () => StockLocation::query()
            ->join('products', 'products.id', '=', 'stock_locations.product_id')
            ->where('products.status', 'active')
            ->where('stock_locations.quantity', '>', 0)
            ->when($storeId, fn ($query) => $query
                ->where('stock_locations.locationable_type', Store::class)
                ->where('stock_locations.locationable_id', $storeId));

        $productQuery = fn () => Product::query()
            ->where('status', 'active')
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId));

        $stockValue = (float) ($stockLocationQuery()
            ->whereNotNull('products.amount')
            ->selectRaw('SUM(stock_locations.quantity * products.amount) as total_value')
            ->value('total_value') ?? 0);

        $unitsInStock = (int) $stockLocationQuery()->sum('stock_locations.quantity');

        $lowStock = (clone $productQuery())->whereBetween('quantity', [1, $lowStockThreshold])->count();

        $outOfStock = (clone $productQuery())->where('quantity', '<=', 0)->count();

        $dailyRevenue = $this->fillDailySeries(
            $transactionQuery()
                ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
                ->selectRaw('DATE(created_at) as date, SUM(amount) as total')
                ->groupBy('date')
                ->pluck('total', 'date'),
            $days,
            true,
        );

        $dailyOrders = $this->fillDailySeries(
            $orderQuery()
                ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
                ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
                ->groupBy('date')
                ->pluck('total', 'date'),
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
            'stores' => $this->storeOptions(),
            'kpis' => [
                // The four legacy-scoped tiles (verify correction C1).
                'revenue_today' => (float) (clone $transactionQuery())->whereDate('created_at', $today)->sum('amount'),
                'orders_today' => (clone $orderQuery())->whereDate('created_at', $today)->count(),
                'revenue_mtd' => (float) (clone $transactionQuery())->whereBetween('created_at', [$startOfMonth, now()])->sum('amount'),
                'stock_value' => $stockValue,
                // Legacy read these two from the global stats array even when
                // a store was selected — keep that scope.
                'active_stores' => Store::query()->where('status', Store::STATUS_ACTIVE)->count(),
                'customers' => Customer::count(),
            ],
            'stats' => $this->stats($lowStock, $outOfStock, $unitsInStock, $stockValue, $lowStockThreshold),
            'daily_revenue' => $dailyRevenue,
            'daily_orders' => $dailyOrders,
            'payment_breakdown' => $this->paymentBreakdown($from, $to, $storeId),
            'top_stores' => $this->topStores($storeId),
            'pending_transfers' => $this->pendingTransfers($storeId),
            'transfer_stats' => $this->transferStats(),
            'recent_transactions' => $this->recentTransactions(),
            'recent_orders' => $this->recentOrders(),
            'recent_businesses' => Business::with('owner:id,name,email')
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn (Business $business) => [
                    'id' => $business->id,
                    'name' => $business->name,
                    'business_code' => $business->business_code,
                    'status' => $business->status,
                    'owner' => $business->owner?->name,
                    'created_at' => $business->created_at?->toISOString(),
                ])->values()->all(),
            // The previous payload shipped these two monthly series but no
            // screen rendered them; the dashboard now charts them (they had no
            // legacy counterpart and stay platform-wide).
            'revenue_series' => $this->monthlySeries(
                fn ($start, $end) => (float) Transaction::query()
                    ->where('status', TransactionStatus::CONFIRMED)
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('amount'),
            ),
            'orders_series' => $this->monthlySeries(
                fn ($start, $end) => (int) Order::query()->whereBetween('created_at', [$start, $end])->count(),
            ),
        ]);
    }

    /**
     * The legacy `$stats` array: platform-wide under every filter.
     *
     * @return array<string, mixed>
     */
    private function stats(int $lowStock, int $outOfStock, int $unitsInStock, float $stockValue, int $lowStockThreshold): array
    {
        return [
            'businesses' => Business::count(),
            'active_businesses' => Business::query()->where('status', 'active')->count(),
            // Non-deleted rather than legacy's raw count — a deleted store is
            // not a store an operator can act on.
            'stores' => Store::query()->where('status', '!=', Store::STATUS_DELETED)->count(),
            'active_stores' => Store::query()->where('status', Store::STATUS_ACTIVE)->count(),
            'total_warehouses' => Warehouse::query()->where('status', '!=', Warehouse::STATUS_DELETED)->count(),
            'users' => User::query()->whereIn('role', [User::ROLE_BUSINESS_OWNER, 'staff'])->count(),
            'staff' => User::query()->where('role', 'staff')->count(),
            // Legacy's "Staff" card counted every business user who is not an
            // owner (staff plus the legacy `user` role).
            'total_staff' => User::query()->whereNotNull('business_id')->where('role', '!=', User::ROLE_BUSINESS_OWNER)->count(),
            'customers' => Customer::count(),
            'new_customers_this_month' => Customer::query()
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
            'active_customers' => Customer::query()->whereNotNull('email_verified_at')->count(),
            'products' => Product::query()->where('status', 'active')->count(),
            'total_products' => Product::count(),
            'active_products' => Product::query()->where('status', 'active')->count(),
            'units_in_stock' => $unitsInStock,
            'stock_value' => $stockValue,
            'low_stock' => $lowStock,
            'low_stock_threshold' => $lowStockThreshold,
            'out_of_stock' => $outOfStock,
            'open_pos_sessions' => PosSession::query()->where('status', PosSession::STATUS_OPEN)->count(),
            'orders' => Order::count(),
            'orders_pending' => Order::query()->where('status', OrderStatus::PENDING)->count(),
            'completed_orders' => Order::query()->where('status', OrderStatus::COMPLETED)->count(),
            'orders_today' => Order::query()->whereDate('created_at', now()->toDateString())->count(),
            'transactions' => Transaction::query()->where('status', TransactionStatus::CONFIRMED)->count(),
            'total_transactions' => Transaction::count(),
            'pending_transactions' => Transaction::query()->where('status', TransactionStatus::PENDING)->count(),
            'revenue_total' => (float) Transaction::query()->where('status', TransactionStatus::CONFIRMED)->sum('amount'),
            'revenue_today' => (float) Transaction::query()
                ->where('status', TransactionStatus::CONFIRMED)
                ->whereDate('created_at', now()->toDateString())
                ->sum('amount'),
            'revenue_mtd' => (float) Transaction::query()
                ->where('status', TransactionStatus::CONFIRMED)
                ->whereBetween('created_at', [now()->startOfMonth(), now()])
                ->sum('amount'),
            'kyc_pending' => KycApplication::query()->where('status', KycApplication::STATUS_SUBMITTED)->count(),
        ];
    }

    /**
     * The store selector options — legacy's `$allStores`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function storeOptions(): array
    {
        return Store::query()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            ->get(['id', 'store_id', 'name', 'status'])
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'store_id' => $store->store_id,
                'name' => $store->name,
                'status' => $store->status,
            ])->values()->all();
    }

    /**
     * Every non-deleted store with its today/MTD pulse, sorted by MTD revenue.
     * Legacy listed every store; the old endpoint listed only the top eight
     * active ones by all-time revenue.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topStores(?int $storeId): array
    {
        $today = now()->toDateString();
        $startOfMonth = now()->startOfMonth();

        return Store::query()
            ->where('stores.status', '!=', Store::STATUS_DELETED)
            ->when($storeId, fn ($query) => $query->where('stores.id', $storeId))
            ->with(['user:id,name', 'business:id,name', 'activePosSession'])
            ->withCount([
                'products',
                'orders',
                'orders as orders_today' => fn ($query) => $query->whereDate('created_at', $today),
            ])
            ->withSum(['orders as revenue_today' => fn ($query) => $query->whereDate('created_at', $today)], 'total')
            ->withSum(['orders as revenue_mtd' => fn ($query) => $query->whereBetween('created_at', [$startOfMonth, now()])], 'total')
            // One correlated sub-select instead of legacy's per-row query.
            ->addSelect(['last_order_at' => Order::query()
                ->select('orders.created_at')
                ->whereColumn('orders.store_id', 'stores.id')
                ->orderByDesc('orders.created_at')
                ->limit(1)])
            ->selectSub(
                Transaction::query()
                    ->selectRaw('COALESCE(SUM(transactions.amount), 0)')
                    ->join('orders', 'orders.id', '=', 'transactions.order_id')
                    ->whereColumn('orders.store_id', 'stores.id')
                    ->where('transactions.status', TransactionStatus::CONFIRMED->value),
                'revenue'
            )
            ->orderByDesc('revenue_mtd')
            ->orderBy('stores.name')
            ->get()
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'store_id' => $store->store_id,
                'name' => $store->name,
                'status' => $store->status,
                'owner' => $store->user?->name,
                'business' => $store->business?->name,
                'revenue_today' => (float) ($store->revenue_today ?? 0),
                'revenue_mtd' => (float) ($store->revenue_mtd ?? 0),
                'orders_today' => (int) ($store->orders_today ?? 0),
                'orders_count' => (int) ($store->orders_count ?? 0),
                'revenue' => (float) ($store->revenue ?? 0),
                'products_count' => (int) ($store->products_count ?? 0),
                'pos_status' => $store->activePosSession ? 'open' : 'closed',
                'last_order_at' => $store->last_order_at ? Carbon::parse($store->last_order_at)->toISOString() : null,
            ])->values()->all();
    }

    /**
     * The five latest pending/approved transfers, scoped by the store filter.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pendingTransfers(?int $storeId): array
    {
        return StockTransfer::query()
            ->whereIn('status', [TransferStatus::PENDING->value, TransferStatus::APPROVED->value])
            ->when($storeId, fn ($query) => $query->where(function ($query) use ($storeId) {
                $query->where(fn ($sub) => $sub
                    ->where('from_location_type', Store::class)
                    ->where('from_location_id', $storeId))
                    ->orWhere(fn ($sub) => $sub
                        ->where('to_location_type', Store::class)
                        ->where('to_location_id', $storeId));
            }))
            ->with(['fromLocation', 'toLocation'])
            ->latest()
            ->limit(5)
            ->get()
            ->map(function (StockTransfer $transfer) {
                $status = $transfer->status instanceof TransferStatus ? $transfer->status : TransferStatus::from($transfer->status);

                return [
                    'transfer_code' => $transfer->transfer_code,
                    'from' => $transfer->fromLocation?->name,
                    'to' => $transfer->toLocation?->name,
                    'status' => $status->value,
                    'status_label' => $status->label(),
                    'created_at' => $transfer->created_at?->toISOString(),
                ];
            })->values()->all();
    }

    /**
     * Counters legacy showed in the transfers panel header. Everything else in
     * the panel is store-scoped; these were not (verify correction C1).
     *
     * @return array<string, int>
     */
    private function transferStats(): array
    {
        $today = now()->toDateString();

        return [
            'pending' => StockTransfer::query()->whereIn('status', [
                TransferStatus::PENDING->value,
                TransferStatus::APPROVED->value,
            ])->count(),
            'today_dispatched' => StockTransfer::query()
                ->where('status', TransferStatus::DISPATCHED->value)
                ->whereDate('updated_at', $today)
                ->count(),
            'today_received' => StockTransfer::query()
                ->where('status', TransferStatus::RECEIVED->value)
                ->whereDate('updated_at', $today)
                ->count(),
        ];
    }

    /**
     * The ten latest confirmed transactions, platform-wide like legacy's
     * `$stats['recent_transactions']`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentTransactions(): array
    {
        return Transaction::query()
            ->where('status', TransactionStatus::CONFIRMED)
            ->with(['order.store:id,name', 'paymentMethod:id,name'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Transaction $transaction) => [
                'reference' => $transaction->reference,
                'order_number' => $transaction->order?->order_number,
                'store' => $transaction->order?->store?->name,
                'payment_method' => $transaction->paymentMethod?->name,
                'amount' => (float) $transaction->amount,
                'status' => $transaction->status instanceof TransactionStatus
                    ? $transaction->status->value
                    : (string) $transaction->status,
                'created_at' => $transaction->created_at?->toISOString(),
            ])->values()->all();
    }

    /**
     * The ten latest orders, platform-wide like legacy's
     * `$stats['recent_orders']`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentOrders(): array
    {
        return Order::query()
            ->with(['customer:id,first_name,last_name', 'store:id,name'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'customer' => $order->customer?->full_name,
                'store' => $order->store?->name,
                'total' => (float) $order->total,
                'status' => $order->status instanceof OrderStatus
                    ? $order->status->value
                    : (string) $order->status,
                'created_at' => $order->created_at?->toISOString(),
            ])->values()->all();
    }

    /**
     * Confirmed payment totals by method for the from/to window (the range
     * scoped exactly this chart in legacy). Store filter applied.
     *
     * @return array<int, array{method: string, count: int, total: float}>
     */
    private function paymentBreakdown(Carbon $from, Carbon $to, ?int $storeId): array
    {
        return Transaction::query()
            ->where('transactions.status', TransactionStatus::CONFIRMED)
            ->when($storeId, fn ($query) => $query->whereHas('order', fn ($order) => $order->where('store_id', $storeId)))
            ->whereBetween('transactions.created_at', [$from, $to])
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'transactions.payment_method_id')
            ->selectRaw('COALESCE(payment_methods.name, ?) as method, COUNT(*) as count, SUM(transactions.amount) as total', ['Other'])
            ->groupBy('method')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'method' => (string) $row->method,
                'count' => (int) $row->count,
                'total' => (float) $row->total,
            ])->values()->all();
    }

    /**
     * Zero-fill a `date => total` map into the fixed window the chart expects.
     *
     * @param  Collection<string, mixed>  $totals
     * @return array<int, array{date: string, total: float|int}>
     */
    private function fillDailySeries(Collection $totals, int $days, bool $money): array
    {
        $series = [];

        for ($daysAgo = $days - 1; $daysAgo >= 0; $daysAgo--) {
            $date = now()->subDays($daysAgo)->toDateString();
            $total = $totals[$date] ?? 0;

            $series[] = [
                'date' => $date,
                'total' => $money ? (float) $total : (int) $total,
            ];
        }

        return $series;
    }

    /**
     * @param  callable(Carbon, Carbon): (float|int)  $value
     * @return array<int, array{month: string, total: float|int}>
     */
    private function monthlySeries(callable $value): array
    {
        $series = [];

        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $start = now()->subMonths($monthsAgo)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            $series[] = [
                'month' => $start->format('Y-m'),
                'total' => $value($start, $end),
            ];
        }

        return $series;
    }
}
