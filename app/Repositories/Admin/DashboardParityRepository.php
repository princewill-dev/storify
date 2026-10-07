<?php

namespace App\Repositories\Admin;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransferStatus;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * WS7 — the dashboard-parity reads behind Api\V1\Admin\DashboardParityController.
 *
 * Every query the dashboard renders is composed here: the store filter, the
 * stock read-outs, the KPI row, the platform-wide stats grid, the daily and
 * monthly series, the payment donut and the three panels. This layer only
 * builds queries and applies eager loads — it never opens a transaction, never
 * calls abort(), and never shapes a response: the controller owns the HTTP
 * shape and `App\Http\Resources\Admin\Dashboard\*` shapes the rows.
 *
 * Store filter scope follows verify correction C1 exactly: only the four
 * legacy-scoped KPI tiles (revenue today, orders today, revenue MTD, stock
 * value), stock units/low/out-of-stock counts, both daily charts, the payment
 * donut, the pending-transfers list and the store table honour `store_id`.
 * The businesses/stores/customers counts, the whole stats grid, the monthly
 * series and the recent transactions/orders feeds are platform-wide, exactly
 * as legacy computed them.
 */
final class DashboardParityRepository
{
    /**
     * Resolve the dashboard's store filter. `store_id` accepts either the
     * numeric id or the public store code; a deleted store is never
     * selectable. A miss returns null — mapping that to the endpoint's 422 is
     * the controller's job, not this layer's.
     */
    public function resolveStoreFilter(string $storeId): ?Store
    {
        $query = Store::query()->where('status', '!=', Store::STATUS_DELETED);

        return is_numeric($storeId)
            ? $query->where('id', (int) $storeId)->first()
            : $query->where('store_id', $storeId)->first();
    }

    /**
     * Legacy stock value/counts read stock_locations joined to active products
     * with stock on hand; a store filter narrows the location.
     *
     * The four read-outs are computed together because the KPI row and the
     * stats grid both render them — they must agree, and they must not run
     * their queries twice.
     *
     * @return array{stock_value: float, units_in_stock: int, low_stock: int, out_of_stock: int}
     */
    public function stockMetrics(?int $storeId, int $lowStockThreshold): array
    {
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

        return [
            'stock_value' => (float) ($stockLocationQuery()
                ->whereNotNull('products.amount')
                ->selectRaw('SUM(stock_locations.quantity * products.amount) as total_value')
                ->value('total_value') ?? 0),
            'units_in_stock' => (int) $stockLocationQuery()->sum('stock_locations.quantity'),
            'low_stock' => (clone $productQuery())->whereBetween('quantity', [1, $lowStockThreshold])->count(),
            'out_of_stock' => (clone $productQuery())->where('quantity', '<=', 0)->count(),
        ];
    }

    /**
     * The KPI row. The four legacy-scoped tiles (verify correction C1) honour
     * the store filter; `active_stores` and `customers` were read from
     * legacy's global stats array even when a store was selected — that scope
     * is kept. The stock value is passed in from `stockMetrics()` so the four
     * stock queries run once per request.
     *
     * @return array<string, float|int>
     */
    public function kpiRow(?int $storeId, float $stockValue): array
    {
        $startOfMonth = now()->startOfMonth();
        $today = now()->toDateString();

        return [
            'revenue_today' => (float) $this->transactionQuery($storeId)->whereDate('created_at', $today)->sum('amount'),
            'orders_today' => $this->orderQuery($storeId)->whereDate('created_at', $today)->count(),
            'revenue_mtd' => (float) $this->transactionQuery($storeId)->whereBetween('created_at', [$startOfMonth, now()])->sum('amount'),
            'stock_value' => $stockValue,
            'active_stores' => Store::query()->where('status', Store::STATUS_ACTIVE)->count(),
            'customers' => Customer::count(),
        ];
    }

    /**
     * The legacy `$stats` array: platform-wide under every filter.
     *
     * The stock block arrives from `stockMetrics()` so its four queries are
     * not repeated here.
     *
     * @param  array{stock_value: float, units_in_stock: int, low_stock: int, out_of_stock: int}  $stock
     * @return array<string, float|int>
     */
    public function statsGrid(array $stock, int $lowStockThreshold): array
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
            'units_in_stock' => $stock['units_in_stock'],
            'stock_value' => $stock['stock_value'],
            'low_stock' => $stock['low_stock'],
            'low_stock_threshold' => $lowStockThreshold,
            'out_of_stock' => $stock['out_of_stock'],
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
     * The store selector options — legacy's `$allStores`. Non-deleted only;
     * the resource layer trims the columns.
     *
     * @return Collection<int, Store>
     */
    public function storeOptions(): Collection
    {
        return Store::query()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            ->get(['id', 'store_id', 'name', 'status']);
    }

    /**
     * The daily revenue totals for the range-pill window, keyed by date. The
     * caller zero-fills the window; money is summed in SQL.
     *
     * @return Collection<string, mixed>
     */
    public function dailyRevenueTotals(?int $storeId, int $days): Collection
    {
        return $this->transactionQuery($storeId)
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw('DATE(created_at) as date, SUM(amount) as total')
            ->groupBy('date')
            ->pluck('total', 'date');
    }

    /**
     * The daily order counts for the range-pill window, keyed by date.
     *
     * @return Collection<string, mixed>
     */
    public function dailyOrderTotals(?int $storeId, int $days): Collection
    {
        return $this->orderQuery($storeId)
            ->where('created_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->groupBy('date')
            ->pluck('total', 'date');
    }

    /**
     * The six-month revenue series the previous payload shipped but no screen
     * rendered. Platform-wide, like the rest of legacy's stats array.
     *
     * @return array<string, float> month (Y-m) => total, oldest first
     */
    public function monthlyRevenueTotals(): array
    {
        $totals = [];

        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $start = now()->subMonths($monthsAgo)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            $totals[$start->format('Y-m')] = (float) $this->transactionQuery(null)
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount');
        }

        return $totals;
    }

    /**
     * The six-month order-count series; platform-wide like the revenue series.
     *
     * @return array<string, int> month (Y-m) => count, oldest first
     */
    public function monthlyOrderTotals(): array
    {
        $totals = [];

        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $start = now()->subMonths($monthsAgo)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            $totals[$start->format('Y-m')] = (int) $this->orderQuery(null)
                ->whereBetween('created_at', [$start, $end])
                ->count();
        }

        return $totals;
    }

    /**
     * Confirmed payment totals by method for the from/to window (the range
     * scoped exactly this chart in legacy). Store filter applied.
     *
     * @return Collection<int, object>
     */
    public function paymentBreakdown(Carbon $from, Carbon $to, ?int $storeId): Collection
    {
        return $this->transactionQuery($storeId)
            ->whereBetween('transactions.created_at', [$from, $to])
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'transactions.payment_method_id')
            ->selectRaw('COALESCE(payment_methods.name, ?) as method, COUNT(*) as count, SUM(transactions.amount) as total', ['Other'])
            ->groupBy('method')
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Every non-deleted store with its today/MTD pulse, sorted by MTD revenue.
     * Legacy listed every store; the old endpoint listed only the top eight
     * active ones by all-time revenue. Store filter applied.
     *
     * @return Collection<int, Store>
     */
    public function topStores(?int $storeId): Collection
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
            ->get();
    }

    /**
     * The five latest pending/approved transfers, scoped by the store filter.
     *
     * @return Collection<int, StockTransfer>
     */
    public function pendingTransfers(?int $storeId): Collection
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
            ->get();
    }

    /**
     * Counters legacy showed in the transfers panel header. Everything else in
     * the panel is store-scoped; these were not (verify correction C1).
     *
     * @return array<string, int>
     */
    public function transferStats(): array
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
     * @return Collection<int, Transaction>
     */
    public function recentTransactions(): Collection
    {
        return Transaction::query()
            ->where('status', TransactionStatus::CONFIRMED)
            ->with(['order.store:id,name', 'paymentMethod:id,name'])
            ->latest()
            ->limit(10)
            ->get();
    }

    /**
     * The ten latest orders, platform-wide like legacy's
     * `$stats['recent_orders']`.
     *
     * @return Collection<int, Order>
     */
    public function recentOrders(): Collection
    {
        return Order::query()
            ->with(['customer:id,first_name,last_name', 'store:id,name'])
            ->latest()
            ->limit(10)
            ->get();
    }

    /**
     * The eight most recent businesses, platform-wide.
     *
     * @return Collection<int, Business>
     */
    public function recentBusinesses(): Collection
    {
        return Business::query()
            ->with('owner:id,name,email')
            ->latest()
            ->limit(8)
            ->get();
    }

    /**
     * Each call builds a fresh base query so callers can compose it freely and
     * the store filter is applied consistently wherever legacy applied it.
     * Confirmed transactions are scoped through their order when a store is
     * selected.
     */
    private function transactionQuery(?int $storeId): Builder
    {
        return Transaction::query()
            ->where('transactions.status', TransactionStatus::CONFIRMED)
            ->when($storeId, fn ($query) => $query->whereHas('order', fn ($order) => $order->where('store_id', $storeId)));
    }

    private function orderQuery(?int $storeId): Builder
    {
        return Order::query()->when($storeId, fn ($query) => $query->where('store_id', $storeId));
    }
}
