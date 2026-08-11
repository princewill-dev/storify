<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        if (!$user->is_verified) {
            return redirect()->route('management.auth.verify-otp')
                ->with('warning', 'Verify your email first to continue.');
        }

        $activeStoreId = session('active_store_id');
        $isRestricted = $user->isRestrictedStaff();

        $storeIds = $isRestricted
            ? $user->assignedStores()->where('status', '!=', 'deleted')->pluck('stores.id')
            : $user->accessibleStores()->where('status', '!=', 'deleted')->pluck('id');

        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonth()->endOfMonth();
        $recentActivityThreshold = $now->copy()->subDays(30);

        // ── Orders ──
        $ordersQuery = Order::query()->where('user_id', $user->id);
        if ($activeStoreId) {
            $ordersQuery->where('store_id', $activeStoreId);
        }

        $orderStats = (clone $ordersQuery)->selectRaw("
            COUNT(*) as total,
            SUM(status = 'pending') as pending,
            SUM(status IN ('accepted', 'processing')) as processing,
            SUM(status IN ('completed', 'delivered')) as completed,
            SUM(created_at BETWEEN ? AND ?) as this_month,
            SUM(created_at BETWEEN ? AND ?) as last_month
        ", [$startOfMonth, $endOfMonth, $lastMonthStart, $lastMonthEnd])->first();

        $totalOrders = (int) $orderStats->total;
        $pendingOrders = (int) $orderStats->pending;
        $processingOrders = (int) $orderStats->processing;
        $completedOrders = (int) $orderStats->completed;
        $ordersThisMonth = (int) $orderStats->this_month;
        $lastMonthOrders = (int) $orderStats->last_month;

        $recentOrders = (clone $ordersQuery)->with(['store', 'items'])->latest()->take(8)->get();

        // ── Transactions ──
        $transactionsQuery = Transaction::query()->where('business_id', $user->business_id);
        if ($activeStoreId) {
            $transactionsQuery->where(function ($q) use ($activeStoreId) {
                $q->whereHas('order', fn($o) => $o->where('store_id', $activeStoreId))
                  ->orWhereHas('invoice', fn($i) => $i->where('store_id', $activeStoreId));
            });
        }

        $completedStatuses = ['confirmed'];

        $txStats = (clone $transactionsQuery)->selectRaw("
            COUNT(*) as total,
            COALESCE(SUM(CASE WHEN status IN ('confirmed') THEN amount ELSE 0 END), 0) as total_revenue,
            COALESCE(SUM(CASE WHEN status IN ('confirmed') AND created_at BETWEEN ? AND ? THEN amount ELSE 0 END), 0) as revenue_this_month,
            COALESCE(SUM(CASE WHEN status IN ('confirmed') AND created_at BETWEEN ? AND ? THEN amount ELSE 0 END), 0) as last_month_revenue,
            COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0) as pending_revenue
        ", [$startOfMonth, $endOfMonth, $lastMonthStart, $lastMonthEnd])->first();

        $totalTransactions = (int) $txStats->total;
        $totalRevenue = (float) ($txStats->total_revenue ?? 0);
        $revenueThisMonth = (float) ($txStats->revenue_this_month ?? 0);
        $lastMonthRevenue = (float) ($txStats->last_month_revenue ?? 0);
        $pendingRevenue = (float) ($txStats->pending_revenue ?? 0);

        $recentTransactions = (clone $transactionsQuery)->with(['order.customer', 'order.store'])->latest()->take(5)->get();

        // ── Customers ──
        $totalCustomers = Customer::whereHas('orders', function ($q) use ($user, $activeStoreId) {
            $q->where('user_id', $user->id);
            if ($activeStoreId) $q->where('store_id', $activeStoreId);
        })->count();

        $activeCustomers = Customer::whereHas('orders', function ($q) use ($user, $activeStoreId, $recentActivityThreshold) {
            $q->where('user_id', $user->id)->where('created_at', '>=', $recentActivityThreshold);
            if ($activeStoreId) $q->where('store_id', $activeStoreId);
        })->count();

        // ── Stores ──
        $storeRelation = $isRestricted ? $user->assignedStores() : $user->accessibleStores();
        $allStoresQuery = (clone $storeRelation)->where('status', '!=', 'deleted');
        $storesStats = (clone $allStoresQuery)->selectRaw("COUNT(*) as total, SUM(status = 'active') as active")->first();
        $totalStores = (int) $storesStats->total;
        $activeStores = (int) $storesStats->active;
        $allStores = (clone $allStoresQuery)->get();
        $activeStoreObj = $allStores->find($activeStoreId);

        $productsQuery = Product::whereIn('store_id', $storeIds);
        if ($activeStoreId) {
            $productsQuery->where('store_id', $activeStoreId);
        }
        $totalProducts = (clone $productsQuery)->count();
        $activeProducts = (clone $productsQuery)->where('status', 'active')->count();

        $warehouseIds = $isRestricted
            ? $user->assignedWarehouses()->where('status', '!=', 'deleted')->pluck('warehouses.id')
            : $user->warehouses()->where('status', '!=', 'deleted')->pluck('id');

        $effectiveStoreIds = $activeStoreId ? [$activeStoreId] : $storeIds;

        $stockLocationQuery = StockLocation::join('products', 'products.id', '=', 'stock_locations.product_id')
            ->where('products.status', 'active')
            ->where('stock_locations.quantity', '>', 0)
            ->where(function ($q) use ($effectiveStoreIds, $warehouseIds) {
                if (!empty($effectiveStoreIds)) {
                    $q->orWhere(function ($sq) use ($effectiveStoreIds) {
                        $sq->where('stock_locations.locationable_type', Store::class)
                           ->whereIn('stock_locations.locationable_id', $effectiveStoreIds);
                    });
                }
                if (!empty($warehouseIds)) {
                    $q->orWhere(function ($wq) use ($warehouseIds) {
                        $wq->where('stock_locations.locationable_type', Warehouse::class)
                           ->whereIn('stock_locations.locationable_id', $warehouseIds);
                    });
                }
            });

        $totalStock = (clone $stockLocationQuery)->sum('stock_locations.quantity');
        $stockValue = (clone $stockLocationQuery)->whereNotNull('products.amount')
            ->selectRaw('SUM(stock_locations.quantity * products.amount) as total_value')
            ->value('total_value') ?? 0;
        $lowStockProducts = Product::whereIn('store_id', $activeStoreId ? [$activeStoreId] : $storeIds)
            ->where('status', 'active')
            ->where('quantity', '<=', 10)
            ->where('quantity', '>', 0)
            ->with('store')
            ->latest()
            ->take(6)
            ->get();
        $outOfStockProducts = Product::whereIn('store_id', $activeStoreId ? [$activeStoreId] : $storeIds)
            ->where('status', 'active')
            ->where('quantity', '<=', 0)
            ->count();

        // ── Staff ──
        $totalStaff = 0;
        $activeStaff = 0;
        $invitedStaff = 0;
        $suspendedStaff = 0;
        $recentStaff = collect();

        if ($user->can('staff view')) {
            $staffQuery = User::where('role', 'staff')->where('business_id', $user->business_id)
                ->where('status', '!=', 'deleted');

            $staffStats = (clone $staffQuery)->selectRaw("
                COUNT(*) as total,
                SUM(status = 'active') as active,
                SUM(status = 'invited') as invited,
                SUM(status = 'suspended') as suspended
            ")->first();

            $totalStaff = (int) $staffStats->total;
            $activeStaff = (int) $staffStats->active;
            $invitedStaff = (int) $staffStats->invited;
            $suspendedStaff = (int) $staffStats->suspended;
            $recentStaff = (clone $staffQuery)->with('roles')->latest()->take(5)->get();
        }

        // ── Warehouses ──
        $warehouseQuery = $isRestricted ? $user->assignedWarehouses() : $user->warehouses();
        $warehouseStats = (clone $warehouseQuery)->selectRaw("COUNT(*) as total, SUM(status != 'deleted') as active")->first();
        $totalWarehouses = (int) $warehouseStats->total;
        $activeWarehouses = (int) $warehouseStats->active;
        $warehouses = (clone $warehouseQuery)->withCount('stockLocations')->get();
        $warehouseTotalStock = $warehouses->sum('stock_locations_count');

        // ── Stock Transfers ──
        $pendingTransfersQuery = \App\Models\StockTransfer::whereIn('status', ['pending', 'approved'])
            ->where(function ($q) use ($storeIds, $warehouseIds) {
                $q->where(function ($sq) use ($storeIds, $warehouseIds) {
                    $sq->where('from_location_type', Store::class)->whereIn('from_location_id', $storeIds)
                       ->orWhere('from_location_type', Warehouse::class)->whereIn('from_location_id', $warehouseIds);
                })->orWhere(function ($sq) use ($storeIds, $warehouseIds) {
                    $sq->where('to_location_type', Store::class)->whereIn('to_location_id', $storeIds)
                       ->orWhere('to_location_type', Warehouse::class)->whereIn('to_location_id', $warehouseIds);
                });
            });

        $pendingTransfers = (clone $pendingTransfersQuery)->count();
        $pendingTransferList = (clone $pendingTransfersQuery)->with(['fromLocation', 'toLocation', 'requester', 'items.product'])->latest()->take(10)->get();

        // ── POS ──
        $openPosSessions = PosSession::whereIn('store_id', $activeStoreId ? [$activeStoreId] : $storeIds)
            ->where('status', PosSession::STATUS_OPEN)
            ->with('staff', 'store')
            ->latest()
            ->get();
        $activePosStores = Store::whereIn('id', $activeStoreId ? [$activeStoreId] : $storeIds)->where('pos_enabled', true)->count();

        // ── Web Visits ──
        $webVisits = Store::whereIn('id', $activeStoreId ? [$activeStoreId] : $storeIds)->where('has_website', true)->count();

        // ── Monthly Orders for Chart ──
        $monthlyOrders = Order::where('user_id', $user->id)
            ->selectRaw('YEAR(created_at) year, MONTH(created_at) month, COUNT(*) count')
            ->where('created_at', '>=', $now->copy()->subMonths(6))
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->map(fn($row) => ['month' => Carbon::create($row->year, $row->month, 1)->format('M'), 'count' => $row->count]);

        $monthlyRevenue = (clone $transactionsQuery)->whereIn('status', $completedStatuses)
            ->selectRaw('YEAR(created_at) year, MONTH(created_at) month, SUM(amount) total')
            ->where('created_at', '>=', $now->copy()->subMonths(6))
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->map(fn($row) => ['month' => Carbon::create($row->year, $row->month, 1)->format('M'), 'total' => (float) $row->total]);

        $stats = [
            'total_revenue' => (float) $totalRevenue,
            'revenue_this_month' => (float) $revenueThisMonth,
            'pending_revenue' => (float) $pendingRevenue,
            'revenue_change_percent' => $lastMonthRevenue > 0
                ? round((($revenueThisMonth - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1)
                : ($revenueThisMonth > 0 ? 100 : 0),
            'total_orders' => $totalOrders,
            'pending_orders' => $pendingOrders,
            'processing_orders' => $processingOrders,
            'completed_orders' => $completedOrders,
            'orders_this_month' => $ordersThisMonth,
            'orders_change_percent' => $lastMonthOrders > 0
                ? round((($ordersThisMonth - $lastMonthOrders) / $lastMonthOrders) * 100, 1)
                : ($ordersThisMonth > 0 ? 100 : 0),
            'total_transactions' => $totalTransactions,
            'recent_transactions' => $recentTransactions,
            'recent_orders' => $recentOrders,
            'total_customers' => $totalCustomers,
            'active_customers' => $activeCustomers,
            'total_stores' => $totalStores,
            'active_stores' => $activeStores,
            'total_products' => $totalProducts,
            'active_products' => $activeProducts,
            'total_stock' => (int) $totalStock,
            'stock_value' => (float) $stockValue,
            'low_stock_products' => $lowStockProducts,
            'out_of_stock' => $outOfStockProducts,
            'total_staff' => $totalStaff,
            'active_staff' => $activeStaff,
            'invited_staff' => $invitedStaff,
            'suspended_staff' => $suspendedStaff,
            'recent_staff' => $recentStaff,
            'total_warehouses' => $totalWarehouses,
            'active_warehouses' => $activeWarehouses,
            'warehouse_total_stock' => (int) $warehouseTotalStock,
            'warehouses' => $warehouses,
            'pending_transfers' => $pendingTransfers,
            'pending_transfer_list' => $pendingTransferList,
            'open_pos_sessions' => $openPosSessions,
            'active_pos_stores' => $activePosStores,
            'web_visits' => $webVisits,
            'monthly_orders' => $monthlyOrders,
            'monthly_revenue' => $monthlyRevenue,
            'all_stores' => $allStores,
        ];

        $stats = [
            'total_revenue' => (float) $totalRevenue,
            'revenue_this_month' => (float) $revenueThisMonth,
            'pending_revenue' => (float) $pendingRevenue,
            'revenue_change_percent' => $lastMonthRevenue > 0
                ? round((($revenueThisMonth - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1)
                : ($revenueThisMonth > 0 ? 100 : 0),
            'total_orders' => $totalOrders,
            'pending_orders' => $pendingOrders,
            'processing_orders' => $processingOrders,
            'completed_orders' => $completedOrders,
            'orders_this_month' => $ordersThisMonth,
            'orders_change_percent' => $lastMonthOrders > 0
                ? round((($ordersThisMonth - $lastMonthOrders) / $lastMonthOrders) * 100, 1)
                : ($ordersThisMonth > 0 ? 100 : 0),
            'total_transactions' => $totalTransactions,
            'recent_transactions' => $recentTransactions,
            'recent_orders' => $recentOrders,
            'total_customers' => $totalCustomers,
            'active_customers' => $activeCustomers,
            'total_stores' => $totalStores,
            'active_stores' => $activeStores,
            'total_products' => $totalProducts,
            'active_products' => $activeProducts,
            'total_stock' => (int) $totalStock,
            'stock_value' => (float) $stockValue,
            'low_stock_products' => $lowStockProducts,
            'out_of_stock' => $outOfStockProducts,
            'total_staff' => $totalStaff,
            'active_staff' => $activeStaff,
            'invited_staff' => $invitedStaff,
            'suspended_staff' => $suspendedStaff,
            'recent_staff' => $recentStaff,
            'total_warehouses' => $totalWarehouses,
            'active_warehouses' => $activeWarehouses,
            'warehouse_total_stock' => (int) $warehouseTotalStock,
            'warehouses' => $warehouses,
            'pending_transfers' => $pendingTransfers,
            'pending_transfer_list' => $pendingTransferList,
            'open_pos_sessions' => $openPosSessions,
            'active_pos_stores' => $activePosStores,
            'web_visits' => $webVisits,
            'monthly_orders' => $monthlyOrders,
            'monthly_revenue' => $monthlyRevenue,
            'all_stores' => $allStores,
        ];

        $breadcrumbs = [['label' => 'Dashboard']];

        return view('management.dashboard', compact('user', 'stats', 'activeStoreId', 'activeStoreObj', 'breadcrumbs'));
    }

    public function switchStore(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Clear store filter to show all stores combined
        if (!$request->filled('store_id')) {
            session()->forget('active_store_id');
            return redirect()->route('management.dashboard')
                ->with('success', 'Showing all stores.');
        }

        $request->validate(['store_id' => 'exists:stores,id']);

        $storeRelation = $user->isRestrictedStaff() ? $user->assignedStores() : $user->accessibleStores();
        if (!$storeRelation->where('status', '!=', 'deleted')->where('id', $request->store_id)->exists()) {
            return back()->with('error', 'Unauthorized store access.');
        }

        session(['active_store_id' => $request->store_id]);
        return redirect()->route('management.dashboard')
            ->with('success', 'Store switched successfully.');
    }
}
