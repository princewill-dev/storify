<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransferStatus;
use App\Enums\WarehouseStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * WS-28 — the full business dashboard payload.
 *
 * Legacy `Management\DashboardController@index` compiled ~40 scalars into one
 * Blade page; this endpoint is the same payload for the SPA. It lives beside
 * `DashboardController` rather than inside it because that controller is owned
 * by the shell workstream — every key this endpoint returns is a superset of
 * what that one already returns, so `DashboardView.vue` alone moves over.
 *
 * Behavioural notes, in the order the legacy page rendered them:
 *
 *  - Cards are permission-gated like the Blade `@can` wrappers: an
 *    unpermitted viewer does not receive the metric or the panel at all
 *    (legacy merely hid them, the data was still computed and shipped).
 *  - The store switcher is stateless now: `?store_id=` is validated against
 *    `accessibleStores()` on every request, not stashed in the session. The
 *    legacy switch endpoint trusted `exists:stores,id` before its access
 *    check; an id outside the circle is a 403 here, so ids can't be probed.
 *  - Legacy computed every order/revenue figure from `user_id = acting user`,
 *    which silently blanked a staff member's view of their employer's data.
 *    Everything here is business-scoped and store-scoped like the rest of
 *    this API.
 *  - The legacy transfer table ignored the selected store; it is scoped here.
 */
class DashboardWidgetsController extends ApiController
{
    use ResolvesManagementContext;

    /**
     * Same threshold as the products list (`ProductListController`), so the
     * dashboard panel, the `/products?low_stock=1` filter and the amber stock
     * badge can never disagree. Legacy's dashboard used `<= 10`, the old API
     * count used 1–5 and the warehouse card uses `min_quantity`; the one
     * definition for product-level low stock is this one. Out-of-stock
     * (`<= 0`) is still reported separately, and the per-location
     * `min_quantity` model stays a warehouse-card indicator (WS-29 owns its
     * editor).
     */
    private const LOW_STOCK_THRESHOLD = 10;

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $businessId = $user->business_id;

        $request->validate(['store_id' => ['nullable', 'integer']]);

        // Every scope decision reads from these two lists; nothing below
        // trusts an id from the request. The assigned-store relation joins
        // `staff_assignments` (which also has an `id`), so the pluck has to
        // name the related table's key — and `accessibleStores()` is a
        // relation for owners and assigned staff but a plain builder for
        // platform admins (CFO, superadmin), so the key comes from the model.
        $storeRelation = $user->accessibleStores();
        $accessibleStoreIds = $storeRelation
            ->where('status', '!=', Store::STATUS_DELETED)
            ->pluck((new Store)->getQualifiedKeyName())
            ->map(fn ($id) => (int) $id)
            ->values();

        $requestedStoreId = $request->filled('store_id') ? (int) $request->integer('store_id') : null;

        if ($requestedStoreId !== null && ! $accessibleStoreIds->contains($requestedStoreId)) {
            abort(403, 'You do not have access to this store.');
        }

        $storeIds = $requestedStoreId === null ? $accessibleStoreIds : collect([$requestedStoreId]);
        $scopedStore = $requestedStoreId === null
            ? null
            : Store::whereKey($requestedStoreId)->first(['id', 'store_id', 'name']);

        $warehouses = $user->accessibleWarehouses()
            ->where('status', '!=', Warehouse::STATUS_DELETED)
            ->get(['warehouses.id', 'warehouses.warehouse_code', 'warehouses.name', 'warehouses.city', 'warehouses.state', 'warehouses.status']);
        $warehouseIds = $warehouses->pluck('id')->map(fn ($id) => (int) $id);

        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonth()->endOfMonth();
        $activeCustomerSince = $now->copy()->subDays(30);

        $orderQuery = Order::query()
            ->where('business_id', $businessId)
            ->whereIn('store_id', $storeIds);

        // Transactions reach their store through the order, or through the
        // invoice for sales raised without one (same union the existing
        // dashboard endpoint uses).
        $transactionQuery = Transaction::query()
            ->where('business_id', $businessId)
            ->where(function (Builder $q) use ($storeIds) {
                $q->whereHas('order', fn ($o) => $o->whereIn('store_id', $storeIds))
                    ->orWhereHas('invoice', fn ($i) => $i->whereIn('store_id', $storeIds));
            });

        $data = [
            'scope' => [
                'store_id' => $requestedStoreId,
                'store' => $scopedStore === null ? null : [
                    'id' => $scopedStore->id,
                    'store_id' => $scopedStore->store_id,
                    'name' => $scopedStore->name,
                ],
            ],
        ];

        // ── Cards ────────────────────────────────────────────────────────────

        $stats = [];

        if ($user->can('transactions view')) {
            $confirmed = $transactionQuery->clone()->where('status', TransactionStatus::CONFIRMED);

            $revenueTotal = round((float) $confirmed->clone()->sum('amount'), 2);
            $revenueThisMonth = round((float) $confirmed->clone()->whereBetween('created_at', [$startOfMonth, $now])->sum('amount'), 2);
            $revenueLastMonth = round((float) $confirmed->clone()->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])->sum('amount'), 2);

            $stats['revenue'] = [
                'total' => $revenueTotal,
                'this_month' => $revenueThisMonth,
                'last_month' => $revenueLastMonth,
                // Legacy's exact definition: no baseline means "up 100% when
                // anything came in, flat otherwise" rather than a division by
                // zero. The audit confirmed only this card carried a %.
                'change_percent' => $revenueLastMonth > 0
                    ? round((($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100, 1)
                    : ($revenueThisMonth > 0 ? 100.0 : 0.0),
            ];
        }

        if ($user->can('orders view')) {
            $orderStats = (clone $orderQuery)
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
                    $startOfMonth, $now,
                    $lastMonthStart, $lastMonthEnd,
                ])->first();

            $ordersThisMonth = (int) $orderStats->this_month;
            $ordersLastMonth = (int) $orderStats->last_month;

            $stats['orders'] = [
                'total' => (int) $orderStats->total,
                'pending' => (int) $orderStats->pending,
                'processing' => (int) $orderStats->processing,
                'completed' => (int) $orderStats->completed,
                'this_month' => $ordersThisMonth,
                'last_month' => $ordersLastMonth,
                // Computed for parity with the legacy controller; the verify
                // pass confirmed the Orders card never rendered it, so the
                // SPA deliberately shows "N pending · N this month" instead.
                'change_percent' => $ordersLastMonth > 0
                    ? round((($ordersThisMonth - $ordersLastMonth) / $ordersLastMonth) * 100, 1)
                    : ($ordersThisMonth > 0 ? 100.0 : 0.0),
            ];
        }

        if ($user->can('customers view')) {
            $stats['customers'] = [
                // Legacy counted customers through their orders (scoped to the
                // acting user and active store), not the customer table.
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

        if ($user->can('products view')) {
            $productQuery = Product::query()
                ->where('business_id', $businessId)
                ->whereIn('store_id', $storeIds);

            $stats['products'] = [
                'total' => $productQuery->clone()->count(),
                'active' => $productQuery->clone()->where('status', 'active')->count(),
            ];

            // Stock lives on stock locations at stores and warehouses (the
            // legacy page counted both); `products.quantity` alone understates
            // a business that just stocked a warehouse.
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

            // SUM/SUM(...) run in decimal on the database side — no PHP float
            // accumulation over rows.
            $stats['stock'] = [
                'total_units' => (int) $activeStock->clone()->sum('stock_locations.quantity'),
                'value' => round((float) ($activeStock->clone()
                    ->whereNotNull('products.amount')
                    ->selectRaw('SUM(stock_locations.quantity * products.amount) as total_value')
                    ->value('total_value') ?? 0), 2),
            ];
        }

        if ($user->can('warehouses view')) {
            $stats['warehouses'] = [
                'total' => $warehouses->count(),
                // `status` is enum-cast on the model, so compare against the
                // enum rather than the string constant.
                'active' => $warehouses->where('status', WarehouseStatus::ACTIVE)->count(),
                // Legacy summed stock-location *rows* and labelled the card
                // "items stocked". Units is what the label promises, so the
                // dashboard sums quantities (the panel still exposes the
                // product count per warehouse).
                'total_stock' => (int) StockLocation::query()
                    ->where('business_id', $businessId)
                    ->where('locationable_type', Warehouse::class)
                    ->whereIn('locationable_id', $warehouseIds)
                    ->where('quantity', '>', 0)
                    ->sum('quantity'),
            ];
        }

        if ($user->can('stores view')) {
            $stats['stores'] = [
                'total' => $storeIds->count(),
                'active' => Store::query()->whereIn('id', $storeIds)->where('status', Store::STATUS_ACTIVE)->count(),
            ];

            $stats['web_visits'] = Store::query()->whereIn('id', $storeIds)->where('has_website', true)->count();
        }

        if ($user->can('staff view')) {
            $staffQuery = User::query()
                ->where('business_id', $businessId)
                ->where('role', 'staff')
                ->where('status', '!=', 'deleted');

            $stats['staff'] = [
                'total' => $staffQuery->clone()->count(),
                'active' => $staffQuery->clone()->where('status', 'active')->count(),
                'invited' => $staffQuery->clone()->where('status', 'invited')->count(),
                'suspended' => $staffQuery->clone()->where('status', 'suspended')->count(),
            ];
        }

        if ($user->can('pos view_history')) {
            $stats['pos'] = [
                'open_sessions' => PosSession::query()
                    ->where('business_id', $businessId)
                    ->whereIn('store_id', $storeIds)
                    ->where('status', PosSession::STATUS_OPEN)
                    ->count(),
                'active_stores' => Store::query()->whereIn('id', $storeIds)->where('pos_enabled', true)->count(),
            ];
        }

        $data['stats'] = $stats;

        // ── Panels ───────────────────────────────────────────────────────────

        if ($user->can('orders view')) {
            $data['recent_orders'] = $orderQuery->clone()
                ->with(['customer:id,first_name,last_name', 'store:id,name'])
                ->withCount('items')
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer' => $order->customer?->full_name,
                    'store' => $order->store?->name,
                    'items_count' => (int) $order->items_count,
                    'total' => (float) $order->total,
                    'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
                    'created_at' => $order->created_at?->toISOString(),
                ])
                ->values()
                ->all();
        }

        if ($user->can('transactions view')) {
            $data['recent_transactions'] = $transactionQuery->clone()
                ->with(['order:id,order_number,customer_id,store_id', 'order.customer:id,first_name,last_name'])
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (Transaction $transaction) => [
                    'id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'order_number' => $transaction->order?->order_number,
                    'customer' => $transaction->order?->customer?->full_name ?? 'Walk-in',
                    'amount' => (float) $transaction->amount,
                    'status' => $transaction->status instanceof TransactionStatus ? $transaction->status->value : $transaction->status,
                    'created_at' => $transaction->created_at?->toISOString(),
                ])
                ->values()
                ->all();

            $data['revenue_series'] = $transactionQuery->clone()
                ->where('status', TransactionStatus::CONFIRMED)
                ->where('created_at', '>=', $now->copy()->subMonths(5)->startOfMonth())
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, SUM(amount) as total")
                ->groupBy('month')
                ->orderBy('month')
                ->get()
                ->map(fn ($row) => ['month' => $row->month, 'total' => round((float) $row->total, 2)])
                ->values()
                ->all();
        }

        if ($user->can('transfers view')) {
            // Pending or approved, touching an accessible location — and when
            // a store is selected, only transfers touching *it* (legacy kept
            // ignoring the store selection here).
            $locationScope = $requestedStoreId === null
                ? [[Store::class, $storeIds], [Warehouse::class, $warehouseIds]]
                : [[Store::class, $storeIds]];

            $transferQuery = StockTransfer::query()
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

            $data['pending_transfer_count'] = $transferQuery->clone()->count();

            $data['transfer_list'] = $transferQuery->clone()
                ->with(['fromLocation:id,name', 'toLocation:id,name'])
                ->withCount('items')
                ->latest()
                ->limit(10)
                ->get()
                ->map(fn (StockTransfer $transfer) => [
                    'id' => $transfer->id,
                    'transfer_code' => $transfer->transfer_code,
                    'from' => $transfer->fromLocation?->name,
                    'to' => $transfer->toLocation?->name,
                    'items_count' => (int) $transfer->items_count,
                    'status' => $transfer->status instanceof TransferStatus ? $transfer->status->value : $transfer->status,
                    'created_at' => $transfer->created_at?->toISOString(),
                ])
                ->values()
                ->all();
        }

        if ($user->can('staff view')) {
            $data['recent_staff'] = User::query()
                ->where('business_id', $businessId)
                ->where('role', 'staff')
                ->where('status', '!=', 'deleted')
                ->with('roles')
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn (User $member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'status' => $member->status,
                    'roles' => $member->roles->pluck('name')->values()->all(),
                ])
                ->values()
                ->all();
        }

        if ($user->can('warehouses view')) {
            $data['warehouses'] = $warehouses->map(fn (Warehouse $warehouse) => [
                'id' => $warehouse->id,
                'warehouse_code' => $warehouse->warehouse_code,
                'name' => $warehouse->name,
                'city' => $warehouse->city,
                'state' => $warehouse->state,
                'is_active' => $warehouse->status === WarehouseStatus::ACTIVE,
                // Both the card count and this list read stock-location rows,
                // the one source of truth (WS-06 stopped the legacy grid from
                // disagreeing with its own count).
                'product_count' => (int) StockLocation::query()
                    ->where('locationable_type', Warehouse::class)
                    ->where('locationable_id', $warehouse->id)
                    ->where('quantity', '>', 0)
                    ->count(),
            ])->values()->all();
        }

        if ($user->can('pos view_history')) {
            $data['pos'] = [
                'open_sessions' => PosSession::query()
                    ->where('business_id', $businessId)
                    ->whereIn('store_id', $storeIds)
                    ->where('status', PosSession::STATUS_OPEN)
                    ->with(['staff:id,name', 'store:id,name'])
                    ->latest()
                    ->get()
                    ->map(fn (PosSession $session) => [
                        'id' => $session->id,
                        'session_code' => $session->session_code,
                        'store' => $session->store?->name,
                        'staff' => $session->staff?->name,
                        'opened_at' => $session->opened_at?->toISOString(),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        // The low-stock panel was never permission-gated in legacy and is a
        // dashboard-level alert, so it stays ungated (business- and
        // store-scoped like everything else).
        $data['low_stock'] = $this->lowStock($businessId, $storeIds);

        return $this->ok($data);
    }

    /**
     * The product-level low-stock panel: active, non-digital products at or
     * below {@see self::LOW_STOCK_THRESHOLD}, split into "1..threshold left"
     * (the legacy list) and the out-of-stock count (the legacy "N out" pill).
     *
     * @param  Collection<int, int>  $storeIds
     * @return array<string, mixed>
     */
    private function lowStock(int $businessId, $storeIds): array
    {
        $variantStock = '(select coalesce(sum(quantity), 0) from product_variants where product_variants.product_id = products.id)';

        // Variant-driven products are measured by their variant total, exactly
        // like ProductListController's filter and per-row `low_stock` flag.
        $threshold = function (string $operator, int $value) use ($variantStock): \Closure {
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
            ->where($threshold('<=', self::LOW_STOCK_THRESHOLD));

        $items = $base->clone()
            ->where($threshold('>', 0))
            ->with('store:id,name')
            ->withSum('variants as variant_stock', 'quantity')
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->name,
                'store' => $product->store?->name,
                'quantity' => (int) ($product->has_variants ? ($product->variant_stock ?? 0) : $product->quantity),
            ])
            ->values()
            ->all();

        return [
            'threshold' => self::LOW_STOCK_THRESHOLD,
            'items' => $items,
            // Counts include out-of-stock, so they agree with the products
            // list's `low_stock` filter; `out_of_stock_count` splits them out.
            'count' => $base->clone()->count(),
            'out_of_stock_count' => $base->clone()->where($threshold('<=', 0))->count(),
        ];
    }
}
