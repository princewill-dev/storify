<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\KycApplication;
use App\Enums\StockMovementType;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = Auth::user();
        $settings = Setting::query()->first();

        $now = Carbon::now();
        $today = $now->toDateString();
        $startOfMonth = $now->copy()->startOfMonth();
        $thirtyDaysAgo = $now->copy()->subDays(30);

        $filterStoreId = $request->query('store_id');
        $fromDate = $request->query('from') ? Carbon::parse($request->query('from'))->startOfDay() : $startOfMonth;
        $toDate = $request->query('to') ? Carbon::parse($request->query('to'))->endOfDay() : $now;

        $orderQuery = Order::query();
        $txnQuery = Transaction::query();
        $movementQuery = StockMovement::query();

        if ($filterStoreId) {
            $orderQuery->where('store_id', $filterStoreId);
            $txnQuery->whereHas('order', fn($q) => $q->where('store_id', $filterStoreId));
            $movementQuery->where(function ($q) use ($filterStoreId) {
                $q->where(function ($sq) use ($filterStoreId) {
                    $sq->where('from_location_type', Store::class)->where('from_location_id', $filterStoreId);
                })->orWhere(function ($sq) use ($filterStoreId) {
                    $sq->where('to_location_type', Store::class)->where('to_location_id', $filterStoreId);
                });
            });
        }

        $todaySales = (clone $txnQuery)->where('status', 'confirmed')->whereDate('created_at', $today)->sum('amount');
        $todayOrders = (clone $orderQuery)->whereDate('created_at', $today)->count();

        $stockLocationQuery = StockLocation::join('products', 'products.id', '=', 'stock_locations.product_id')
            ->where('products.status', 'active')
            ->where('stock_locations.quantity', '>', 0);

        if ($filterStoreId) {
            $stockLocationQuery->where('stock_locations.locationable_type', Store::class)
                ->where('stock_locations.locationable_id', $filterStoreId);
        }

        $totalStock = (clone $stockLocationQuery)->sum('stock_locations.quantity');
        $stockValue = (clone $stockLocationQuery)->whereNotNull('products.amount')
            ->selectRaw('SUM(stock_locations.quantity * products.amount) as total_value')
            ->value('total_value') ?? 0;

        $lowStockCount = Product::where('status', 'active')
            ->where('quantity', '<=', 10)->where('quantity', '>', 0)
            ->when($filterStoreId, fn($q) => $q->where('store_id', $filterStoreId))->count();

        $outOfStockCount = Product::where('status', 'active')
            ->where('quantity', '<=', 0)
            ->when($filterStoreId, fn($q) => $q->where('store_id', $filterStoreId))->count();

        $mtdRevenue = (clone $txnQuery)->where('status', 'confirmed')
            ->whereBetween('created_at', [$startOfMonth, $now])->sum('amount');

        $mtdOrders = (clone $orderQuery)->whereBetween('created_at', [$startOfMonth, $now])->count();

        $stockInToday = (clone $movementQuery)->where('type', StockMovement::TYPE_ADDED)
            ->whereDate('created_at', $today)->sum('quantity');

        $stockOutToday = (clone $movementQuery)->where('type', StockMovement::TYPE_REMOVED)
            ->whereDate('created_at', $today)->sum('quantity');

        $dailyRevenue = (clone $txnQuery)->where('status', 'confirmed')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('DATE(created_at) as date, SUM(amount) as total')
            ->groupBy('date')->orderBy('date')->get()
            ->map(fn($r) => ['date' => $r->date, 'total' => (float) $r->total]);

        $dailyOrders = (clone $orderQuery)->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')->orderBy('date')->get()
            ->map(fn($r) => ['date' => $r->date, 'count' => (int) $r->count]);

        $paymentBreakdown = Transaction::where('status', 'confirmed')
            ->when($filterStoreId, fn($q) => $q->whereHas('order', fn($o) => $o->where('store_id', $filterStoreId)))
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->selectRaw('payment_method_id, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('payment_method_id')->with('paymentMethod')->get()
            ->map(fn($t) => [
                'method' => $t->paymentMethod?->name ?? 'Other',
                'count' => (int) $t->count,
                'total' => (float) $t->total,
            ]);

        $pendingTransfers = \App\Models\StockTransfer::whereIn('status', ['pending', 'approved'])
            ->when($filterStoreId, fn($q) => $q->where(function ($sq) use ($filterStoreId) {
                $sq->where(fn($s) => $s->where('to_location_type', Store::class)->where('to_location_id', $filterStoreId))
                  ->orWhere(fn($s) => $s->where('from_location_type', Store::class)->where('from_location_id', $filterStoreId));
            }))
            ->with(['fromLocation', 'toLocation'])->latest()->take(5)->get();

        $transferStats = [
            'pending' => \App\Models\StockTransfer::whereIn('status', ['pending', 'approved'])->count(),
            'today_dispatched' => \App\Models\StockTransfer::where('status', 'dispatched')->whereDate('updated_at', $today)->count(),
            'today_received' => \App\Models\StockTransfer::where('status', 'received')->whereDate('updated_at', $today)->count(),
        ];

        $storeQuery = Store::where('status', '!=', 'deleted');
        if ($filterStoreId) $storeQuery->where('id', $filterStoreId);

        $stores = $storeQuery->with(['user', 'activePosSession'])
            ->withCount(['products', 'orders as orders_today' => fn($q) => $q->whereDate('created_at', $today)])
            ->withSum(['orders as revenue_today' => fn($q) => $q->whereDate('created_at', $today)], 'total')
            ->withSum(['orders as revenue_mtd' => fn($q) => $q->whereBetween('created_at', [$startOfMonth, $now])], 'total')
            ->orderByDesc('revenue_mtd')->get()
            ->map(function ($store) {
                $store->products_count = $store->products()->count();
                $store->pos_status = $store->activePosSession ? 'open' : 'closed';
                $store->last_order_at = $store->orders()->latest()->value('created_at');
                return $store;
            });

        $allStores = Store::where('status', '!=', 'deleted')->orderBy('name')->get();

        $stats = [
            'total_revenue' => Transaction::where('status', 'confirmed')->sum('amount'),
            'total_transactions' => Transaction::count(),
            'pending_transactions' => Transaction::where('status', 'pending')->count(),
            'total_orders' => Order::count(),
            'pending_orders' => Order::where('status', 'pending')->count(),
            'completed_orders' => Order::where('status', 'completed')->count(),
            'total_businesses' => Business::count(),
            'active_businesses' => Business::where('status', 'active')->count(),
            'total_stores' => Store::count(),
            'active_stores' => Store::where('status', 'active')->count(),
            'total_warehouses' => Warehouse::where('status', '!=', 'deleted')->count(),
            'total_staff' => User::whereNotNull('business_id')->where('role', '!=', 'business_owner')->count(),
            'total_products' => Product::count(),
            'active_products' => Product::where('status', 'active')->count(),
            'total_customers' => Customer::count(),
            'new_customers_this_month' => Customer::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count(),
            'active_customers' => Customer::whereNotNull('email_verified_at')->count(),
            'open_pos_sessions' => PosSession::where('status', 'open')->count(),
            'kyc_pending' => KycApplication::where('status', 'submitted')->count(),
            'recent_transactions' => Transaction::where('status', 'confirmed')->with(['order.store', 'paymentMethod'])->latest()->take(10)->get(),
            'recent_orders' => Order::with(['customer', 'store'])->latest()->take(10)->get(),
        ];

        return view('admin.index', compact(
            'stats', 'allStores', 'filterStoreId', 'fromDate', 'toDate',
            'todaySales', 'todayOrders', 'totalStock', 'stockValue', 'lowStockCount', 'outOfStockCount',
            'mtdRevenue', 'mtdOrders', 'stockInToday', 'stockOutToday',
            'pendingTransfers', 'transferStats', 'paymentBreakdown', 'stores',
            'dailyRevenue', 'dailyOrders',
        ));
    }
}
