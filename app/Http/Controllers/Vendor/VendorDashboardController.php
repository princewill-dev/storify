<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class VendorDashboardController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        if (!$vendor->is_verified) {
            return redirect()->route('vendor.auth.verify-otp')
                ->with('warning', 'Verify your email first to continue onboarding.');
        }

        $now = Carbon::now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonth()->endOfMonth();
        $recentActivityThreshold = $now->copy()->subDays(30);

        // Store Selection Logic
        $activeStoreId = session('active_store_id');
        if (!$activeStoreId) {
            $firstStore = $vendor->stores()->first();
            $activeStoreId = $firstStore?->id;
            session(['active_store_id' => $activeStoreId]);
        }

        $ordersQuery = Order::query()->where('vendor_id', $vendor->id);
        
        // If we have an active store, filter accordingly
        if ($activeStoreId) {
            $ordersQuery->where('store_id', $activeStoreId);
        }

        $totalOrders = (clone $ordersQuery)->count();
        $pendingOrders = (clone $ordersQuery)->where('status', 'pending')->count();
        $ordersThisMonth = (clone $ordersQuery)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->count();
        $lastMonthOrders = (clone $ordersQuery)
            ->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])
            ->count();

        $transactionsQuery = Transaction::query()->whereHas('order', function ($query) use ($vendor, $activeStoreId) {
            $query->where('vendor_id', $vendor->id);
            if ($activeStoreId) {
                $query->where('store_id', $activeStoreId);
            }
        });

        $completedStatuses = ['confirmed', 'success'];
        $completedTransactionsQuery = (clone $transactionsQuery)->whereIn('status', $completedStatuses);

        $totalRevenue = (clone $completedTransactionsQuery)->sum('amount');
        $revenueThisMonth = (clone $completedTransactionsQuery)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->sum('amount');
        $lastMonthRevenue = (clone $completedTransactionsQuery)
            ->whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])
            ->sum('amount');

        $totalTransactions = (clone $transactionsQuery)->count();
        $pendingTransactions = (clone $transactionsQuery)->where('status', 'pending')->count();
        $recentTransactions = (clone $transactionsQuery)
            ->with(['order.customer', 'paymentMethod'])
            ->latest()
            ->take(10)
            ->get();

        $totalCustomers = Customer::query()
            ->whereHas('orders', function ($query) use ($vendor, $activeStoreId) {
                $query->where('vendor_id', $vendor->id);
                if ($activeStoreId) {
                    $query->where('store_id', $activeStoreId);
                }
            })
            ->count();

        $activeCustomers = Customer::query()
            ->whereHas('orders', function ($query) use ($vendor, $activeStoreId, $recentActivityThreshold) {
                $query->where('vendor_id', $vendor->id)
                    ->where('created_at', '>=', $recentActivityThreshold);
                if ($activeStoreId) {
                    $query->where('store_id', $activeStoreId);
                }
            })
            ->count();

        $storeQuery = Store::query()->where('vendor_id', $vendor->id);
        $totalStores = (clone $storeQuery)->count();
        $activeStores = (clone $storeQuery)->where('status', 'active')->count();

        $productsQuery = Product::query();
        if ($activeStoreId) {
            $productsQuery->where('store_id', $activeStoreId);
        } else {
            $productsQuery->whereIn('store_id', $vendor->stores()->pluck('id'));
        }

        $totalProducts = (clone $productsQuery)->count();
        $activeProducts = (clone $productsQuery)->where('status', 'active')->count();
        $totalStock = (clone $productsQuery)->sum('quantity');
        $lowStockProducts = (clone $productsQuery)->where('quantity', '<=', 10)->count();

        // Active Items (Products + Services)
        $activeServicesCount = \App\Models\Service::query()
            ->where('status', 'active');
        if ($activeStoreId) {
            $activeServicesCount->where('store_id', $activeStoreId);
        } else {
            $activeServicesCount->whereIn('store_id', $vendor->stores()->pluck('id'));
        }
        $totalItems = $activeProducts + $activeServicesCount->count();

        $stats = [
            'total_revenue' => (float) $totalRevenue,
            'revenue_this_month' => (float) $revenueThisMonth,
            'revenue_change_percent' => $lastMonthRevenue > 0
                ? round((($revenueThisMonth - $lastMonthRevenue) / $lastMonthRevenue) * 100, 2)
                : ($revenueThisMonth > 0 ? 100 : 0),
            'total_orders' => $totalOrders,
            'pending_orders' => $pendingOrders,
            'orders_change_percent' => $lastMonthOrders > 0
                ? round((($ordersThisMonth - $lastMonthOrders) / $lastMonthOrders) * 100, 2)
                : ($ordersThisMonth > 0 ? 100 : 0),
            'total_transactions' => $totalTransactions,
            'pending_transactions' => $pendingTransactions,
            'recent_transactions' => $recentTransactions,
            'total_customers' => $totalCustomers,
            'active_customers' => $activeCustomers,
            'total_vendors' => 1,
            'active_vendors' => $vendor->status === Vendor::STATUS_ACTIVE ? 1 : 0,
            'total_stores' => $totalStores,
            'active_stores' => $activeStores,
            'total_products' => $totalProducts,
            'active_products' => $activeProducts,
            'total_stock' => (int) $totalStock,
            'low_stock_products' => $lowStockProducts,
            'total_items' => $totalItems,
        ];

        return view('vendors.dashboard', compact('vendor', 'stats', 'activeStoreId'));
    }

    public function switchStore(Request $request): RedirectResponse
    {
        $request->validate([
            'store_id' => 'required|exists:stores,id'
        ]);

        /** @var Vendor $vendor */
        $vendor = $request->user('vendor');

        // Verify the store belongs to the vendor
        if (!$vendor->stores()->where('id', $request->store_id)->exists()) {
            return back()->with('error', 'Unauthorized store access.');
        }

        session(['active_store_id' => $request->store_id]);

        return back()->with('success', 'Store switched successfully.');
    }
}
