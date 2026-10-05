<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $businessId = $user->business_id;

        $storeIds = $user->accessibleStores()->pluck('id');
        $storeFilter = $request->filled('store_id')
            ? $storeIds->intersect([(int) $request->integer('store_id')])
            : $storeIds;

        $orderQuery = Order::query()
            ->where('business_id', $businessId)
            ->whereIn('store_id', $storeFilter);

        $txnQuery = Transaction::query()
            ->where('business_id', $businessId)
            ->where('status', TransactionStatus::CONFIRMED)
            ->where(function ($q) use ($storeFilter) {
                $q->whereHas('order', fn ($o) => $o->whereIn('store_id', $storeFilter))
                    ->orWhereHas('invoice', fn ($i) => $i->whereIn('store_id', $storeFilter));
            });

        $startOfMonth = Carbon::now()->startOfMonth();

        $revenueTotal = round((float) $txnQuery->clone()->sum('amount'), 2);
        $revenueThisMonth = round((float) $txnQuery->clone()->where('created_at', '>=', $startOfMonth)->sum('amount'), 2);

        $stats = [
            'revenue' => [
                'total' => $revenueTotal,
                'this_month' => $revenueThisMonth,
            ],
            'orders' => [
                'total' => $orderQuery->clone()->count(),
                'pending' => $orderQuery->clone()->where('status', OrderStatus::PENDING->value)->count(),
                'this_month' => $orderQuery->clone()->where('created_at', '>=', $startOfMonth)->count(),
            ],
            'customers' => [
                'total' => Customer::where('business_id', $businessId)->count(),
            ],
            'products' => [
                'total' => Product::where('business_id', $businessId)->whereIn('store_id', $storeFilter)->count(),
                'low_stock' => Product::where('business_id', $businessId)
                    ->whereIn('store_id', $storeFilter)
                    ->where('is_digital', false)
                    ->whereBetween('quantity', [1, 5])
                    ->count(),
            ],
            'stores' => [
                'total' => $storeFilter->count(),
            ],
        ];

        $recentOrders = $orderQuery->clone()
            ->with(['customer:id,first_name,last_name', 'store:id,name'])
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'customer' => $order->customer?->full_name,
                'store' => $order->store?->name,
                'total' => (float) $order->total,
                'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
                'created_at' => $order->created_at?->toISOString(),
            ])
            ->values()
            ->all();

        $revenueSeries = Transaction::query()
            ->where('business_id', $businessId)
            ->where('status', TransactionStatus::CONFIRMED)
            ->where('created_at', '>=', Carbon::now()->subMonths(5)->startOfMonth())
            ->where(function ($q) use ($storeFilter) {
                $q->whereHas('order', fn ($o) => $o->whereIn('store_id', $storeFilter))
                    ->orWhereHas('invoice', fn ($i) => $i->whereIn('store_id', $storeFilter));
            })
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, SUM(amount) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => ['month' => $row->month, 'total' => round((float) $row->total, 2)])
            ->values()
            ->all();

        return $this->ok([
            'stats' => $stats,
            'recent_orders' => $recentOrders,
            'revenue_series' => $revenueSeries,
        ]);
    }
}
