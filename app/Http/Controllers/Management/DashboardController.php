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
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        /** @var User|null $vendor */
        $vendor = $request->user();

        if (!$vendor) {
            return redirect()->route('management.auth.login');
        }

        if (!$vendor->is_verified) {
            return redirect()->route('management.auth.verify-otp')
                ->with('warning', 'Verify your email first to continue.');
        }

        $activeStoreId = session('active_store_id');
        if (!$activeStoreId) {
            $firstStore = $vendor->stores()->first();
            $activeStoreId = $firstStore?->id;
            if ($activeStoreId) {
                session(['active_store_id' => $activeStoreId]);
            }
        }

        $storeIds = $vendor->stores()->pluck('id');

        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonth()->endOfMonth();
        $recentActivityThreshold = $now->copy()->subDays(30);

        // ── Orders ──
        $ordersQuery = Order::query()->where('user_id', $vendor->id);
        if ($activeStoreId) {
            $ordersQuery->where('store_id', $activeStoreId);
        }

        $totalOrders = (clone $ordersQuery)->count();
        $pendingOrders = (clone $ordersQuery)->where('status', 'pending')->count();
        $processingOrders = (clone $ordersQuery)->whereIn('status', ['accepted', 'processing'])->count();
        $completedOrders = (clone $ordersQuery)->whereIn('status', ['completed', 'delivered'])->count();
        $ordersThisMonth = (clone $ordersQuery)->whereBetween('created_at', [$startOfMonth, $endOfMonth])->count();
        $lastMonthOrders = (clone $ordersQuery)->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])->count();

        $recentOrders = (clone $ordersQuery)->with(['store', 'items'])->latest()->take(8)->get();

        // ── Transactions ──
        $transactionsQuery = Transaction::query()->whereHas('order', function ($q) use ($vendor, $activeStoreId) {
            $q->where('user_id', $vendor->id);
            if ($activeStoreId) $q->where('store_id', $activeStoreId);
        });

        $completedStatuses = ['confirmed'];
        $completedTx = (clone $transactionsQuery)->whereIn('status', $completedStatuses);
        $pendingTx = (clone $transactionsQuery)->where('status', 'pending');

        $totalRevenue = (clone $completedTx)->sum('amount');
        $revenueThisMonth = (clone $completedTx)->whereBetween('created_at', [$startOfMonth, $endOfMonth])->sum('amount');
        $lastMonthRevenue = (clone $completedTx)->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])->sum('amount');
        $pendingRevenue = (clone $pendingTx)->sum('amount');

        $totalTransactions = (clone $transactionsQuery)->count();
        $recentTransactions = (clone $transactionsQuery)->with(['order.customer', 'order.store'])->latest()->take(5)->get();

        // ── Customers ──
        $totalCustomers = Customer::whereHas('orders', function ($q) use ($vendor, $activeStoreId) {
            $q->where('user_id', $vendor->id);
            if ($activeStoreId) $q->where('store_id', $activeStoreId);
        })->count();

        $activeCustomers = Customer::whereHas('orders', function ($q) use ($vendor, $activeStoreId, $recentActivityThreshold) {
            $q->where('user_id', $vendor->id)->where('created_at', '>=', $recentActivityThreshold);
            if ($activeStoreId) $q->where('store_id', $activeStoreId);
        })->count();

        // ── Stores ──
        $allStores = $vendor->stores;
        $totalStores = $allStores->count();
        $activeStores = $allStores->where('status', 'active')->count();
        $activeStoreObj = $allStores->find($activeStoreId);

        $productsQuery = Product::whereIn('store_id', $storeIds);
        $totalProducts = (clone $productsQuery)->count();
        $activeProducts = (clone $productsQuery)->where('status', 'active')->count();
        $totalStock = (clone $productsQuery)->sum('quantity');
        $lowStockProducts = Product::whereIn('store_id', $storeIds)
            ->where('status', 'active')
            ->where('quantity', '<=', 10)
            ->where('quantity', '>', 0)
            ->with('store')
            ->latest()
            ->take(6)
            ->get();
        $outOfStockProducts = Product::whereIn('store_id', $storeIds)
            ->where('status', 'active')
            ->where('quantity', '<=', 0)
            ->count();

        // ── Staff ──
        $staffQuery = User::where('role', 'staff')->where('business_id', $vendor->business_id);
        $totalStaff = (clone $staffQuery)->count();
        $activeStaff = (clone $staffQuery)->where('status', 'active')->count();
        $invitedStaff = (clone $staffQuery)->where('status', 'invited')->count();
        $suspendedStaff = (clone $staffQuery)->where('status', 'suspended')->count();
        $recentStaff = (clone $staffQuery)->with('roles')->latest()->take(5)->get();

        // ── Warehouses ──
        $totalWarehouses = $vendor->warehouses()->count();
        $activeWarehouses = $vendor->warehouses()->where('is_active', true)->count();
        $warehouseTotalStock = StockLocation::whereIn('locationable_id', $vendor->warehouses()->pluck('id'))
            ->where('locationable_type', Warehouse::class)
            ->sum('quantity');
        $warehouses = $vendor->warehouses()->withCount('stockLocations')->get();

        // ── POS ──
        $openPosSessions = PosSession::whereIn('store_id', $storeIds)
            ->where('status', PosSession::STATUS_OPEN)
            ->with('staff', 'store')
            ->latest()
            ->get();
        $activePosStores = Store::where('user_id', $vendor->id)->where('pos_enabled', true)->count();

        // ── Web Visits ──
        $webVisits = Store::where('user_id', $vendor->id)->where('has_website', true)->count();

        // ── Monthly Orders for Chart ──
        $monthlyOrders = Order::where('user_id', $vendor->id)
            ->selectRaw('YEAR(created_at) year, MONTH(created_at) month, COUNT(*) count')
            ->where('created_at', '>=', $now->copy()->subMonths(6))
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->map(fn($row) => ['month' => Carbon::create($row->year, $row->month, 1)->format('M'), 'count' => $row->count]);

        $monthlyRevenue = (clone $completedTx)
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
            'open_pos_sessions' => $openPosSessions,
            'active_pos_stores' => $activePosStores,
            'web_visits' => $webVisits,
            'monthly_orders' => $monthlyOrders,
            'monthly_revenue' => $monthlyRevenue,
            'all_stores' => $allStores,
        ];

        return view('management.dashboard', compact('vendor', 'stats', 'activeStoreId', 'activeStoreObj'));
    }

    public function switchStore(Request $request): RedirectResponse
    {
        $request->validate(['store_id' => 'required|exists:stores,id']);

        $vendor = $request->user();

        if (!$vendor->stores()->where('id', $request->store_id)->exists()) {
            return back()->with('error', 'Unauthorized store access.');
        }

        session(['active_store_id' => $request->store_id]);
        return back()->with('success', 'Store switched successfully.');
    }
}
