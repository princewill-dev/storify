<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Business;
use App\Models\Customer;
use App\Models\KycApplication;
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
        $confirmed = fn ($query) => $query->where('status', TransactionStatus::CONFIRMED);

        $days = (int) $request->integer('days', 30);
        if (! in_array($days, [7, 30, 90], true)) {
            $days = 30;
        }

        return $this->ok([
            'range_days' => $days,
            'stats' => [
                'businesses' => Business::count(),
                'active_businesses' => Business::where('status', 'active')->count(),
                'stores' => Store::where('status', '!=', Store::STATUS_DELETED)->count(),
                'active_stores' => Store::where('status', Store::STATUS_ACTIVE)->count(),
                'users' => User::whereIn('role', ['business_owner', 'staff'])->count(),
                'staff' => User::where('role', 'staff')->count(),
                'customers' => Customer::count(),
                'products' => Product::where('status', 'active')->count(),
                'low_stock' => Product::where('status', 'active')
                    ->where('is_digital', false)
                    ->whereBetween('quantity', [1, 5])
                    ->count(),
                'orders' => Order::count(),
                'orders_pending' => Order::where('status', 'pending')->count(),
                'orders_today' => Order::whereDate('created_at', today())->count(),
                'transactions' => Transaction::where('status', TransactionStatus::CONFIRMED)->count(),
                'revenue_total' => (float) $confirmed(Transaction::query())->sum('amount'),
                'revenue_today' => (float) $confirmed(Transaction::query())->whereDate('created_at', today())->sum('amount'),
                'revenue_mtd' => (float) $confirmed(Transaction::query())
                    ->whereBetween('created_at', [now()->startOfMonth(), now()])
                    ->sum('amount'),
                'kyc_pending' => KycApplication::where('status', KycApplication::STATUS_SUBMITTED)->count(),
            ],
            'recent_businesses' => Business::with('owner:id,name,email')
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn (Business $business) => [
                    'id' => $business->id,
                    'name' => $business->name,
                    'business_code' => $business->business_code,
                    'status' => $business->status,
                    'owner' => $business->owner?->name,
                    'created_at' => $business->created_at?->toISOString(),
                ])->values()->all(),
            'daily_revenue' => $this->dailySeries(
                fn ($start, $end) => (float) $confirmed(Transaction::query())
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('amount'),
                $days
            ),
            'daily_orders' => $this->dailySeries(
                fn ($start, $end) => (int) Order::whereBetween('created_at', [$start, $end])->count(),
                $days
            ),
            'payment_breakdown' => $this->paymentBreakdown(),
            'top_stores' => $this->topStores(),
            'revenue_series' => $this->monthlySeries(
                fn ($start, $end) => (float) $confirmed(Transaction::query())
                    ->whereBetween('created_at', [$start, $end])
                    ->sum('amount')
            ),
            'orders_series' => $this->monthlySeries(
                fn ($start, $end) => (int) Order::whereBetween('created_at', [$start, $end])->count()
            ),
        ]);
    }

    /**
     * @param  callable(Carbon, Carbon): (float|int)  $value
     * @return array<int, array{month: string, total: float|int}>
     */
    private function monthlySeries(callable $value): array
    {
        $series = [];

        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $start = now()->subMonths($monthsAgo)->startOfMonth();
            $end = $start->copy()->endOfMonth();

            $series[] = [
                'month' => $start->format('Y-m'),
                'total' => $value($start, $end),
            ];
        }

        return $series;
    }

    /**
     * @param  callable(Carbon, Carbon): (float|int)  $value
     * @return array<int, array{date: string, total: float|int}>
     */
    private function dailySeries(callable $value, int $days): array
    {
        $series = [];

        for ($daysAgo = $days - 1; $daysAgo >= 0; $daysAgo--) {
            $start = now()->subDays($daysAgo)->startOfDay();
            $end = $start->copy()->endOfDay();

            $series[] = [
                'date' => $start->format('Y-m-d'),
                'total' => $value($start, $end),
            ];
        }

        return $series;
    }

    /**
     * @return array<int, array{method: string, count: int, total: float}>
     */
    private function paymentBreakdown(): array
    {
        $rows = Transaction::query()
            ->where('transactions.status', TransactionStatus::CONFIRMED)
            ->leftJoin('payment_methods', 'payment_methods.id', '=', 'transactions.payment_method_id')
            ->selectRaw('COALESCE(payment_methods.name, ?) as method, COUNT(*) as count, SUM(transactions.amount) as total', ['Other'])
            ->groupBy('method')
            ->orderByDesc('total')
            ->get();

        return $rows->map(fn ($row) => [
            'method' => (string) $row->method,
            'count' => (int) $row->count,
            'total' => (float) $row->total,
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topStores(): array
    {
        return Store::query()
            ->where('stores.status', Store::STATUS_ACTIVE)
            ->with('business:id,name')
            ->select('stores.id', 'stores.name', 'stores.business_id')
            ->selectSub(
                Order::query()->selectRaw('COUNT(*)')->whereColumn('orders.store_id', 'stores.id'),
                'orders_count'
            )
            ->selectSub(
                Transaction::query()
                    ->selectRaw('COALESCE(SUM(transactions.amount), 0)')
                    ->join('orders', 'orders.id', '=', 'transactions.order_id')
                    ->whereColumn('orders.store_id', 'stores.id')
                    ->where('transactions.status', TransactionStatus::CONFIRMED->value),
                'revenue'
            )
            ->orderByDesc('revenue')
            ->limit(8)
            ->get()
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'business' => $store->business?->name,
                'orders_count' => (int) ($store->orders_count ?? 0),
                'revenue' => (float) ($store->revenue ?? 0),
            ])
            ->values()
            ->all();
    }
}
