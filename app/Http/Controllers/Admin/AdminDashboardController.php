<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    /**
     * Show the admin dashboard.
     *
     * @return \Illuminate\View\View
     */
    public function index(Request $request): View
    {
        $user = Auth::user();
        $settings = Setting::query()->first();
        $mainStoreId = $settings->main_store_id ?? null;
        $store = null;
        if ($mainStoreId) {
            $store = Store::find($mainStoreId);
        }
        if (!$store) {
            $store = Store::where('status', 'active')->orderBy('id')->first();
        }

        // Calculate dashboard metrics
        $stats = [
            // Customer metrics
            'total_customers' => Customer::count(),
            'active_customers' => Customer::whereNotNull('email_verified_at')->count(),
            'new_customers_this_month' => Customer::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
            
            // Order metrics
            'total_orders' => Order::count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'completed_orders' => Order::where('status', 'completed')->count(),
            'orders_this_month' => Order::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
            
            // Business, Warehouse & Store metrics
            'total_businesses' => Business::count(),
            'active_businesses' => Business::where('status', 'active')->count(),
            'total_warehouses' => Warehouse::where('status', '!=', 'deleted')->count(),
            'total_staff' => User::whereNotNull('business_id')->where('role', '!=', 'business_owner')->count(),
            'total_stores' => Store::count(),
            'active_stores' => Store::where('status', 'active')->count(),
            
            // Product metrics
            'total_products' => Product::count(),
            'active_products' => Product::where('status', 'active')->count(),
            'total_stock' => Product::sum('quantity'),
            'low_stock_products' => Product::where('quantity', '<=', 10)->count(), // Products with 10 or fewer items
            
            // Financial metrics
            'total_revenue' => Transaction::where('status', 'completed')->sum('amount'),
            'revenue_this_month' => Transaction::where('status', 'completed')
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('amount'),
            'total_transactions' => Transaction::count(),
            'pending_transactions' => Transaction::where('status', 'pending')->count(),
            
            // Recent transactions for table
            'recent_transactions' => Transaction::with(['order.customer', 'paymentMethod'])
                ->latest()
                ->take(10)
                ->get(),
            
            // Recent orders
            'recent_orders' => Order::with(['customer', 'store'])
                ->latest()
                ->take(5)
                ->get(),
        ];

        // Calculate percentage changes (comparing to last month)
        $lastMonthRevenue = Transaction::where('status', 'completed')
            ->whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->sum('amount');
        
        $stats['revenue_change_percent'] = $lastMonthRevenue > 0 
            ? round((($stats['revenue_this_month'] - $lastMonthRevenue) / $lastMonthRevenue) * 100, 2)
            : 0;

        $lastMonthOrders = Order::whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->count();
        
        $stats['orders_change_percent'] = $lastMonthOrders > 0
            ? round((($stats['orders_this_month'] - $lastMonthOrders) / $lastMonthOrders) * 100, 2)
            : 0;

        return view('admin.index', compact('store', 'stats'));
    }
}
