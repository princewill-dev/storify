<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;

final class StoreAnalyticsService
{
    public function dashboard(Store $store): array
    {
        $now = now();
        $transactionStats = Transaction::query()
            ->where('status', 'confirmed')
            ->where(fn ($query) => $query
                ->whereHas('order', fn ($orders) => $orders->where('store_id', $store->id))
                ->orWhereHas('invoice', fn ($invoices) => $invoices->where('store_id', $store->id)))
            ->selectRaw(
                'COALESCE(SUM(amount), 0) total_revenue,
                 COALESCE(SUM(CASE WHEN COALESCE(paid_at, created_at) BETWEEN ? AND ? THEN amount ELSE 0 END), 0) revenue_this_month,
                 COALESCE(SUM(CASE WHEN COALESCE(paid_at, created_at) BETWEEN ? AND ? THEN amount ELSE 0 END), 0) revenue_last_month',
                [
                    $now->copy()->startOfMonth(), $now->copy()->endOfMonth(),
                    $now->copy()->subMonth()->startOfMonth(), $now->copy()->subMonth()->endOfMonth(),
                ]
            )->first();
        $currentRevenue = (int) $transactionStats->revenue_this_month;
        $lastRevenue = (int) $transactionStats->revenue_last_month;
        $orderStats = Order::where('store_id', $store->id)
            ->selectRaw("COUNT(*) total, SUM(status = 'pending') pending, SUM(status IN ('completed', 'delivered')) completed")
            ->first();
        $productStats = Product::where('store_id', $store->id)
            ->selectRaw("COUNT(*) total, SUM(status = 'active') active, COALESCE(SUM(quantity), 0) total_stock")
            ->first();

        return [
            'totalRevenue' => (int) $transactionStats->total_revenue,
            'revenueThisMonth' => $currentRevenue,
            'revenueChange' => $lastRevenue > 0 ? round((($currentRevenue - $lastRevenue) / $lastRevenue) * 100, 1) : ($currentRevenue > 0 ? 100 : 0),
            'totalOrders' => (int) $orderStats->total,
            'pendingOrders' => (int) $orderStats->pending,
            'completedOrders' => (int) $orderStats->completed,
            'productCount' => (int) $productStats->total,
            'activeProducts' => (int) $productStats->active,
            'totalStock' => (int) $productStats->total_stock,
            'customerCount' => Order::where('store_id', $store->id)->whereNotNull('customer_id')->distinct()->count('customer_id'),
            'recentOrders' => $store->orders()->with('customer')->latest()->take(8)->get(),
            'lowStockProducts' => Product::where('store_id', $store->id)->whereBetween('quantity', [1, 10])->where('status', 'active')->take(6)->get(),
            'outOfStock' => Product::where('store_id', $store->id)->where('quantity', '<=', 0)->where('status', 'active')->count(),
            'activePosSession' => PosSession::where('store_id', $store->id)->where('status', PosSession::STATUS_OPEN)->with('staff')->first(),
            'monthlyRevenue' => $this->monthlyRevenue($store),
        ];
    }

    public function web(Store $store): array
    {
        $monthlyData = Order::where('store_id', $store->id)
            ->where('source', 'checkout')
            ->where('created_at', '>=', now()->subMonths(6)->startOfMonth())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') month, COUNT(*) count")
            ->groupBy('month')->get()->keyBy('month');

        return [
            'storeViews' => $store->views,
            'productViews' => Product::where('store_id', $store->id)->sum('views'),
            'webOrders' => Order::where('store_id', $store->id)->where('source', 'checkout')->count(),
            'webRevenue' => (int) Transaction::query()->where('status', 'confirmed')
                ->where(fn ($query) => $query
                    ->whereHas('order', fn ($orders) => $orders->where('store_id', $store->id)->where('source', 'checkout'))
                    ->orWhereHas('invoice', fn ($invoices) => $invoices->where('store_id', $store->id)))
                ->sum('amount'),
            'topProducts' => Product::where('store_id', $store->id)->orderByDesc('views')->take(10)->get(['id', 'name', 'views']),
            'recentActivity' => ActivityLog::where('subject_type', Store::class)->where('subject_id', $store->id)->latest()->take(10)->get(),
            'monthlyWebOrders' => $this->sixMonthSeries($monthlyData, 'count'),
            'storeUrl' => $store->slug
                ? (app()->environment('local') ? url($store->slug) : 'https://'.$store->slug.'.'.config('app.main_domain', 'storify.ng'))
                : null,
        ];
    }

    private function monthlyRevenue(Store $store): array
    {
        $data = Transaction::query()->where('status', 'confirmed')
            ->where(fn ($query) => $query
                ->whereHas('order', fn ($orders) => $orders->where('store_id', $store->id))
                ->orWhereHas('invoice', fn ($invoices) => $invoices->where('store_id', $store->id)))
            ->whereRaw('COALESCE(paid_at, created_at) >= ?', [now()->subMonths(6)->startOfMonth()])
            ->selectRaw("DATE_FORMAT(COALESCE(paid_at, created_at), '%Y-%m') month, COALESCE(SUM(amount), 0) total")
            ->groupBy('month')->get()->keyBy('month');

        return $this->sixMonthSeries($data, 'total');
    }

    private function sixMonthSeries($data, string $value): array
    {
        $series = [];
        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $month = now()->subMonths($monthsAgo);
            $series[] = [
                'month' => $month->format('M'),
                $value => (int) ($data->get($month->format('Y-m'))?->{$value} ?? 0),
            ];
        }

        return $series;
    }
}
