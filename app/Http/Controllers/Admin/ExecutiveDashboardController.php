<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ExecutiveDashboardController extends Controller
{
    public function index(Request $request): View
    {
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

        $todaySales = (clone $txnQuery)->where('status', 'confirmed')
            ->whereDate('created_at', $today)->sum('amount');

        $todayOrders = (clone $orderQuery)->whereDate('created_at', $today)->count();

        $totalStock = Product::when($filterStoreId, fn($q) => $q->where('store_id', $filterStoreId))
            ->sum('quantity');

        $lowStockCount = Product::where('status', 'active')
            ->where('quantity', '<=', 10)->where('quantity', '>', 0)
            ->when($filterStoreId, fn($q) => $q->where('store_id', $filterStoreId))
            ->count();

        $outOfStockCount = Product::where('status', 'active')
            ->where('quantity', '<=', 0)
            ->when($filterStoreId, fn($q) => $q->where('store_id', $filterStoreId))
            ->count();

        $activeStores = Store::where('status', 'active')->count();

        $openPosSessions = PosSession::where('status', 'open')->count();

        $stockValue = Product::when($filterStoreId, fn($q) => $q->where('store_id', $filterStoreId))
            ->where('status', 'active')
            ->selectRaw('SUM(quantity * amount) as total_value')
            ->value('total_value') ?? 0;

        $totalCustomers = \App\Models\Customer::count();
        $newCustomersThisMonth = \App\Models\Customer::whereBetween('created_at', [$startOfMonth, $now])->count();

        $paymentBreakdown = Transaction::where('status', 'confirmed')
            ->when($filterStoreId, fn($q) => $q->whereHas('order', fn($o) => $o->where('store_id', $filterStoreId)))
            ->whereBetween('created_at', [$fromDate, $toDate])
            ->selectRaw('payment_method_id, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('payment_method_id')
            ->with('paymentMethod')
            ->get()
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
            ->with(['fromLocation', 'toLocation'])
            ->latest()->take(5)->get();

        $transferStats = [
            'pending' => \App\Models\StockTransfer::whereIn('status', ['pending', 'approved'])->count(),
            'today_dispatched' => \App\Models\StockTransfer::where('status', 'dispatched')->whereDate('updated_at', $today)->count(),
            'today_received' => \App\Models\StockTransfer::where('status', 'received')->whereDate('updated_at', $today)->count(),
        ];

        $mtdRevenue = (clone $txnQuery)->where('status', 'confirmed')
            ->whereBetween('created_at', [$startOfMonth, $now])->sum('amount');

        $mtdOrders = (clone $orderQuery)->whereBetween('created_at', [$startOfMonth, $now])->count();

        $stockInToday = (clone $movementQuery)
            ->where('type', StockMovement::TYPE_ADDED)
            ->whereDate('created_at', $today)->sum('quantity');

        $stockOutToday = (clone $movementQuery)
            ->where('type', StockMovement::TYPE_REMOVED)
            ->whereDate('created_at', $today)->sum('quantity');

        $stores = Store::with(['user', 'activePosSession'])
            ->withCount(['products', 'orders as orders_today' => fn($q) => $q->whereDate('created_at', $today)])
            ->withSum(['orders as revenue_today' => fn($q) => $q->whereDate('created_at', $today)], 'total')
            ->withSum(['orders as revenue_mtd' => fn($q) => $q->whereBetween('created_at', [$startOfMonth, $now])], 'total')
            ->orderByDesc('revenue_mtd')
            ->get()
            ->map(function ($store) {
                $store->products_count = $store->products()->count();
                $store->pos_status = $store->activePosSession ? 'open' : 'closed';
                $store->last_order_at = $store->orders()->latest()->value('created_at');
                return $store;
            });

        $allStores = Store::orderBy('name')->get();

        $dailyRevenue = (clone $txnQuery)->where('status', 'confirmed')
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('DATE(created_at) as date, SUM(amount) as total')
            ->groupBy('date')->orderBy('date')
            ->get()
            ->map(fn($r) => ['date' => $r->date, 'total' => (float) $r->total]);

        $dailyOrders = (clone $orderQuery)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')->orderBy('date')
            ->get()
            ->map(fn($r) => ['date' => $r->date, 'count' => (int) $r->count]);

        $dailyStockIn = (clone $movementQuery)->where('type', StockMovement::TYPE_ADDED)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('DATE(created_at) as date, SUM(quantity) as total')
            ->groupBy('date')->orderBy('date')
            ->get()
            ->map(fn($r) => ['date' => $r->date, 'total' => (int) ($r->total ?? 0)]);

        $dailyStockOut = (clone $movementQuery)->where('type', StockMovement::TYPE_REMOVED)
            ->where('created_at', '>=', $thirtyDaysAgo)
            ->selectRaw('DATE(created_at) as date, SUM(quantity) as total')
            ->groupBy('date')->orderBy('date')
            ->get()
            ->map(fn($r) => ['date' => $r->date, 'total' => (int) ($r->total ?? 0)]);

        $recentTransactions = Transaction::where('status', 'confirmed')
            ->with(['order.store', 'paymentMethod'])
            ->latest()->take(10)->get();

        return view('admin.executive.index', compact(
            'todaySales', 'todayOrders', 'totalStock', 'lowStockCount', 'outOfStockCount',
            'stockValue', 'activeStores', 'openPosSessions', 'mtdRevenue', 'mtdOrders',
            'stockInToday', 'stockOutToday', 'stores', 'allStores', 'filterStoreId',
            'dailyRevenue', 'dailyOrders', 'dailyStockIn', 'dailyStockOut',
            'recentTransactions', 'paymentBreakdown', 'totalCustomers', 'newCustomersThisMonth',
            'pendingTransfers', 'transferStats', 'fromDate', 'toDate',
        ));
    }
}
