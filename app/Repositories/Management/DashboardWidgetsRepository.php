<?php

namespace App\Repositories\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransferStatus;
use App\Enums\WarehouseStatus;
use App\Models\Customer;
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
 * WS-28 — every read the business dashboard payload renders.
 *
 * The two tenancy circles, the order/transaction base scopes, each card's
 * aggregates, the panels' eager loads and the low-stock composition live here.
 * This layer only builds queries and applies eager loads: it never opens a
 * transaction, never aborts an HTTP request and never shapes a response — the
 * controller owns the 403 for a store outside the circle and the payload key
 * order, and `App\Http\Resources\Management\Dashboard\*` shapes the rows.
 *
 * Time windows all derive from the single `$now` the controller passes, so
 * every card and panel in one request reads the same instant.
 */
final class DashboardWidgetsRepository
{
    /**
     * Every scope decision reads from this list; nothing trusts an id from the
     * request.
     *
     * The assigned-store relation joins `staff_assignments` (which also has an
     * `id`), so the pluck has to name the related table's key — and
     * `accessibleStores()` is a relation for owners and assigned staff but a
     * plain builder for platform admins (CFO, superadmin), so the key comes
     * from the model.
     *
     * @return Collection<int, int>
     */
    public function accessibleStoreIds(User $user): Collection
    {
        return $user->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->pluck((new Store)->getQualifiedKeyName())
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /**
     * The warehouses the caller may see (deleted rows excluded), in the column
     * set the warehouse card and panel render.
     *
     * @return Collection<int, Warehouse>
     */
    public function accessibleWarehouses(User $user): Collection
    {
        return $user->accessibleWarehouses()
            ->where('status', '!=', Warehouse::STATUS_DELETED)
            ->get(['warehouses.id', 'warehouses.warehouse_code', 'warehouses.name', 'warehouses.city', 'warehouses.state', 'warehouses.status']);
    }

    /**
     * Confirmed revenue in scope: all time, this month and last month.
     *
     * @return array{total: float, this_month: float, last_month: float}
     */
    public function revenueTotals(int $businessId, Collection $storeIds, Carbon $now): array
    {
        $confirmed = $this->transactionQuery($businessId, $storeIds)
            ->where('status', TransactionStatus::CONFIRMED);

        return [
            'total' => round((float) $confirmed->clone()->sum('amount'), 2),
            'this_month' => round((float) $confirmed->clone()
                ->whereBetween('created_at', [$now->copy()->startOfMonth(), $now])
                ->sum('amount'), 2),
            'last_month' => round((float) $confirmed->clone()
                ->whereBetween('created_at', [$now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth()])
                ->sum('amount'), 2),
        ];
    }

    /**
     * The Orders card in one aggregate query: total plus the status buckets
     * and this/last month counts.
     *
     * @return array{total: int, pending: int, processing: int, completed: int, this_month: int, last_month: int}
     */
    public function orderStats(int $businessId, Collection $storeIds, Carbon $now): array
    {
        $stats = $this->orderQuery($businessId, $storeIds)
            ->selectRaw('
                COUNT(*) as total,
                SUM(status = ?) as pending,
                SUM(status IN (?, ?)) as processing,
                SUM(status IN (?, ?)) as completed,
                SUM(created_at BETWEEN ? AND ?) as this_month,
                SUM(created_at BETWEEN ? AND ?) as last_month
            ', [
                OrderStatus::PENDING->value,
                OrderStatus::ACCEPTED->value,
                OrderStatus::PROCESSING->value,
                OrderStatus::COMPLETED->value,
                OrderStatus::DELIVERED->value,
                $now->copy()->startOfMonth(), $now,
                $now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth(),
            ])->first();

        return [
            'total' => (int) $stats->total,
            'pending' => (int) $stats->pending,
            'processing' => (int) $stats->processing,
            'completed' => (int) $stats->completed,
            'this_month' => (int) $stats->this_month,
            'last_month' => (int) $stats->last_month,
        ];
    }

    /**
     * The customers card. Legacy counted customers through their orders
     * (scoped to the acting user and active store), not the customer table —
     * `active` is "ordered in the last 30 days".
     *
     * @return array{total: int, active: int}
     */
    public function customerStats(int $businessId, Collection $storeIds, Carbon $now): array
    {
        $activeCustomerSince = $now->copy()->subDays(30);

        return [
            'total' => Customer::query()
                ->where('business_id', $businessId)
                ->whereHas('orders', fn ($q) => $q->whereIn('store_id', $storeIds))
                ->count(),
            'active' => Customer::query()
                ->where('business_id', $businessId)
                ->whereHas('orders', fn ($q) => $q->whereIn('store_id', $storeIds)->where('created_at', '>=', $activeCustomerSince))
                ->count(),
        ];
    }

    /**
     * The products card counts, in scope.
     *
     * @return array{total: int, active: int}
     */
    public function productStats(int $businessId, Collection $storeIds): array
    {
        $productQuery = Product::query()
            ->where('business_id', $businessId)
            ->whereIn('store_id', $storeIds);

        return [
            'total' => $productQuery->clone()->count(),
            'active' => $productQuery->clone()->where('status', 'active')->count(),
        ];
    }

    /**
     * The stock card. Stock lives on stock locations at stores and warehouses
     * (the legacy page counted both); `products.quantity` alone understates a
     * business that just stocked a warehouse.
     *
     * SUM/SUM(...) run in decimal on the database side — no PHP float
     * accumulation over rows.
     *
     * @return array{total_units: int, value: float}
     */
    public function stockStats(int $businessId, Collection $storeIds, Collection $warehouseIds): array
    {
        $activeStock = StockLocation::query()
            ->join('products', 'products.id', '=', 'stock_locations.product_id')
            ->where('stock_locations.business_id', $businessId)
            ->where('products.status', 'active')
            ->where('stock_locations.quantity', '>', 0)
            ->where(function ($q) use ($storeIds, $warehouseIds) {
                $q->where(fn ($store) => $store
                    ->where('stock_locations.locationable_type', Store::class)
                    ->whereIn('stock_locations.locationable_id', $storeIds))
                    ->orWhere(fn ($warehouse) => $warehouse
                        ->where('stock_locations.locationable_type', Warehouse::class)
                        ->whereIn('stock_locations.locationable_id', $warehouseIds));
            });

        return [
            'total_units' => (int) $activeStock->clone()->sum('stock_locations.quantity'),
            'value' => round((float) ($activeStock->clone()
                ->whereNotNull('products.amount')
                ->selectRaw('SUM(stock_locations.quantity * products.amount) as total_value')
                ->value('total_value') ?? 0), 2),
        ];
    }

    /**
     * The warehouses card. `total_stock` sums stock-location *quantities*:
     * legacy summed rows and labelled the card "items stocked", but units are
     * what the label promises (the panel still exposes the product count per
     * warehouse).
     *
     * @param  Collection<int, Warehouse>  $warehouses
     * @param  Collection<int, int>  $warehouseIds
     * @return array{total: int, active: int, total_stock: int}
     */
    public function warehouseStats(int $businessId, Collection $warehouses, Collection $warehouseIds): array
    {
        return [
            'total' => $warehouses->count(),
            // `status` is enum-cast on the model, so compare against the enum
            // rather than the string constant.
            'active' => $warehouses->where('status', WarehouseStatus::ACTIVE)->count(),
            'total_stock' => (int) StockLocation::query()
                ->where('business_id', $businessId)
                ->where('locationable_type', Warehouse::class)
                ->whereIn('locationable_id', $warehouseIds)
                ->where('quantity', '>', 0)
                ->sum('quantity'),
        ];
    }

    /**
     * The stores card plus `web_visits` (stores with a website), both over the
     * same scope list.
     *
     * @param  Collection<int, int>  $storeIds
     * @return array{total: int, active: int, web_visits: int}
     */
    public function storeStats(Collection $storeIds): array
    {
        return [
            'total' => $storeIds->count(),
            'active' => Store::query()->whereIn('id', $storeIds)->where('status', Store::STATUS_ACTIVE)->count(),
            'web_visits' => Store::query()->whereIn('id', $storeIds)->where('has_website', true)->count(),
        ];
    }

    /**
     * The staff card, business-wide (staff are not store-scoped).
     *
     * @return array{total: int, active: int, invited: int, suspended: int}
     */
    public function staffStats(int $businessId): array
    {
        $staffQuery = User::query()
            ->where('business_id', $businessId)
            ->where('role', 'staff')
            ->where('status', '!=', 'deleted');

        return [
            'total' => $staffQuery->clone()->count(),
            'active' => $staffQuery->clone()->where('status', 'active')->count(),
            'invited' => $staffQuery->clone()->where('status', 'invited')->count(),
            'suspended' => $staffQuery->clone()->where('status', 'suspended')->count(),
        ];
    }

    /**
     * The POS card: open sessions in scope and stores with POS enabled.
     *
     * @return array{open_sessions: int, active_stores: int}
     */
    public function posStats(int $businessId, Collection $storeIds): array
    {
        return [
            'open_sessions' => PosSession::query()
                ->where('business_id', $businessId)
                ->whereIn('store_id', $storeIds)
                ->where('status', PosSession::STATUS_OPEN)
                ->count(),
            'active_stores' => Store::query()->whereIn('id', $storeIds)->where('pos_enabled', true)->count(),
        ];
    }

    /**
     * The recent-orders panel: newest first, item count included.
     *
     * @return Collection<int, Order>
     */
    public function recentOrders(int $businessId, Collection $storeIds, int $limit): Collection
    {
        return $this->orderQuery($businessId, $storeIds)
            ->with(['customer:id,first_name,last_name', 'store:id,name'])
            ->withCount('items')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * The recent-transactions panel: newest first, with the order and its
     * customer for the Walk-in fallback.
     *
     * @return Collection<int, Transaction>
     */
    public function recentTransactions(int $businessId, Collection $storeIds, int $limit): Collection
    {
        return $this->transactionQuery($businessId, $storeIds)
            ->with(['order:id,order_number,customer_id,store_id', 'order.customer:id,first_name,last_name'])
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * The revenue series behind the chart: one row per month since six months
     * ago (start of month), oldest first.
     *
     * @return Collection<int, Transaction>
     */
    public function revenueSeries(int $businessId, Collection $storeIds, Carbon $now): Collection
    {
        return $this->transactionQuery($businessId, $storeIds)
            ->where('status', TransactionStatus::CONFIRMED)
            ->where('created_at', '>=', $now->copy()->subMonths(5)->startOfMonth())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, SUM(amount) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->get();
    }

    /**
     * Pending or approved transfers touching an accessible location — and when
     * a store is selected, only transfers touching *it* (legacy kept ignoring
     * the store selection here).
     */
    private function transferQuery(int $businessId, ?int $requestedStoreId, Collection $storeIds, Collection $warehouseIds): Builder
    {
        $locationScope = $requestedStoreId === null
            ? [[Store::class, $storeIds], [Warehouse::class, $warehouseIds]]
            : [[Store::class, $storeIds]];

        return StockTransfer::query()
            ->where('business_id', $businessId)
            ->whereIn('status', [TransferStatus::PENDING->value, TransferStatus::APPROVED->value])
            ->where(function ($q) use ($locationScope) {
                $q->where(function ($inner) use ($locationScope) {
                    foreach (['from', 'to'] as $side) {
                        foreach ($locationScope as [$class, $ids]) {
                            $inner->orWhere(fn ($part) => $part
                                ->where("{$side}_location_type", $class)
                                ->whereIn("{$side}_location_id", $ids));
                        }
                    }
                });
            });
    }

    /**
     * The transfers panel's header count.
     */
    public function pendingTransferCount(int $businessId, ?int $requestedStoreId, Collection $storeIds, Collection $warehouseIds): int
    {
        return $this->transferQuery($businessId, $requestedStoreId, $storeIds, $warehouseIds)->count();
    }

    /**
     * The transfers panel list: newest first, with the item count.
     *
     * @return Collection<int, StockTransfer>
     */
    public function recentTransfers(int $businessId, ?int $requestedStoreId, Collection $storeIds, Collection $warehouseIds, int $limit): Collection
    {
        return $this->transferQuery($businessId, $requestedStoreId, $storeIds, $warehouseIds)
            ->with(['fromLocation:id,name', 'toLocation:id,name'])
            ->withCount('items')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * The recent-staff panel, newest first (staff are not store-scoped).
     *
     * @return Collection<int, User>
     */
    public function recentStaff(int $businessId, int $limit): Collection
    {
        return User::query()
            ->where('business_id', $businessId)
            ->where('role', 'staff')
            ->where('status', '!=', 'deleted')
            ->with('roles')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * The open POS sessions the panel lists, with staff and store names.
     *
     * @return Collection<int, PosSession>
     */
    public function openPosSessions(int $businessId, Collection $storeIds): Collection
    {
        return PosSession::query()
            ->where('business_id', $businessId)
            ->whereIn('store_id', $storeIds)
            ->where('status', PosSession::STATUS_OPEN)
            ->with(['staff:id,name', 'store:id,name'])
            ->latest()
            ->get();
    }

    /**
     * Stocked-product counts per warehouse row, for the warehouses panel.
     *
     * Both the card count and this list read stock-location rows, the one
     * source of truth (WS-06 stopped the legacy grid from disagreeing with its
     * own count). Counts are per-row on purpose: the query is the one the
     * panel always ran, not a new aggregate.
     *
     * @param  Collection<int, Warehouse>  $warehouses
     * @return array<int, int> warehouse id => stocked product count
     */
    public function stockedProductCounts(Collection $warehouses): array
    {
        return $warehouses->mapWithKeys(fn (Warehouse $warehouse) => [
            $warehouse->id => (int) StockLocation::query()
                ->where('locationable_type', Warehouse::class)
                ->where('locationable_id', $warehouse->id)
                ->where('quantity', '>', 0)
                ->count(),
        ])->all();
    }

    /**
     * The low-stock panel: active, non-digital products at or below the
     * threshold, split into "1..threshold left" (the legacy list, capped at
     * six rows) and the out-of-stock count (the legacy "N out" pill).
     *
     * Variant-driven products are measured by their variant total, exactly
     * like ProductListController's filter and per-row `low_stock` flag.
     *
     * @param  Collection<int, int>  $storeIds
     * @return array{items: Collection<int, Product>, count: int, out_of_stock_count: int}
     */
    public function lowStockPanel(int $businessId, Collection $storeIds, int $threshold): array
    {
        $variantStock = '(select coalesce(sum(quantity), 0) from product_variants where product_variants.product_id = products.id)';

        $thresholdFilter = function (string $operator, int $value) use ($variantStock): \Closure {
            return function ($q) use ($operator, $value, $variantStock) {
                $q->where(fn ($inner) => $inner
                    ->where('has_variants', true)
                    ->whereRaw("{$variantStock} {$operator} ?", [$value]))
                    ->orWhere(fn ($inner) => $inner
                        ->where('has_variants', false)
                        ->where('quantity', $operator, $value));
            };
        };

        $base = Product::query()
            ->where('business_id', $businessId)
            ->whereIn('store_id', $storeIds)
            ->where('status', 'active')
            ->where('is_digital', false)
            ->where($thresholdFilter('<=', $threshold));

        $items = $base->clone()
            ->where($thresholdFilter('>', 0))
            ->with('store:id,name')
            ->withSum('variants as variant_stock', 'quantity')
            ->latest()
            ->limit(6)
            ->get();

        return [
            'items' => $items,
            // Counts include out-of-stock, so they agree with the products
            // list's `low_stock` filter; `out_of_stock_count` splits them out.
            'count' => $base->clone()->count(),
            'out_of_stock_count' => $base->clone()->where($thresholdFilter('<=', 0))->count(),
        ];
    }

    /**
     * Orders in scope: business-scoped and store-scoped like the rest of this
     * API.
     */
    private function orderQuery(int $businessId, Collection $storeIds): Builder
    {
        return Order::query()
            ->where('business_id', $businessId)
            ->whereIn('store_id', $storeIds);
    }

    /**
     * Transactions in scope. Transactions reach their store through the order,
     * or through the invoice for sales raised without one (same union the
     * existing dashboard endpoint uses).
     */
    private function transactionQuery(int $businessId, Collection $storeIds): Builder
    {
        return Transaction::query()
            ->where('business_id', $businessId)
            ->where(function (Builder $q) use ($storeIds) {
                $q->whereHas('order', fn ($o) => $o->whereIn('store_id', $storeIds))
                    ->orWhereHas('invoice', fn ($i) => $i->whereIn('store_id', $storeIds));
            });
    }
}
