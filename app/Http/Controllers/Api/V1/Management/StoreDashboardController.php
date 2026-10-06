<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PosSession;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StoreDashboardController extends ApiController
{
    use ResolvesManagementContext;

    /**
     * Physical products at or below this quantity surface as low stock.
     *
     * The legacy window (1–10) was unordered, and its list never agreed with
     * the count rendered next to it. WS-29 reconciled the three legacy
     * thresholds into one documented default; this card reads the same value
     * as the product list and the business dashboard, so a store's "low" is
     * one number everywhere.
     */
    private const LOW_STOCK_THRESHOLD = 10;

    private const LOW_STOCK_LIMIT = 6;

    /**
     * The store detail shell's dashboard tab.
     */
    public function show(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        // Deleted stores used to leak back into every store read; refuse them
        // here too rather than relying on route middleware alone.
        if ($store->status === Store::STATUS_DELETED) {
            abort(403, 'This store has been deleted.');
        }

        $now = Carbon::now();
        $thisMonthStart = $now->copy()->startOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonth()->endOfMonth();

        $revenueThisMonth = $this->confirmedRevenue($store, $thisMonthStart, $now);
        $revenueLastMonth = $this->confirmedRevenue($store, $lastMonthStart, $lastMonthEnd);

        // A percentage is a ratio, not a money figure — the revenue sums it
        // is derived from stay as stored.
        $changePercent = $revenueLastMonth > 0
            ? round((($revenueThisMonth - $revenueLastMonth) / $revenueLastMonth) * 100, 1)
            : ($revenueThisMonth > 0 ? 100.0 : 0.0);

        $orderQuery = Order::query()
            ->where('business_id', $store->business_id)
            ->where('store_id', $store->id);

        $completedOrders = $orderQuery->clone()->where('status', OrderStatus::COMPLETED->value)->count();
        $deliveredOrders = $orderQuery->clone()->where('status', OrderStatus::DELIVERED->value)->count();

        $productQuery = Product::query()
            ->where('business_id', $store->business_id)
            ->where('store_id', $store->id);

        $lowStockItems = $productQuery->clone()
            ->where('is_digital', false)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->where('quantity', '<=', self::LOW_STOCK_THRESHOLD)
            ->orderBy('quantity')
            ->orderBy('name')
            ->limit(self::LOW_STOCK_LIMIT)
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->name,
                'quantity' => (int) $product->quantity,
                'status' => $product->status,
            ])
            ->values()
            ->all();

        $activePosSession = $store->pos_enabled
            ? PosSession::query()
                ->where('store_id', $store->id)
                ->where('status', PosSession::STATUS_OPEN)
                ->with('staff:id,name')
                ->latest('opened_at')
                ->first()
            : null;

        return $this->ok([
            'store' => $this->storePayload($store),
            'stats' => [
                'revenue' => [
                    'total' => $this->confirmedRevenue($store),
                    'this_month' => $revenueThisMonth,
                    'last_month' => $revenueLastMonth,
                    'change_percent' => $changePercent,
                    'change_direction' => $changePercent > 0 ? 'up' : ($changePercent < 0 ? 'down' : 'flat'),
                ],
                'orders' => [
                    'total' => $orderQuery->clone()->count(),
                    'pending' => $orderQuery->clone()->where('status', OrderStatus::PENDING->value)->count(),
                    'completed' => $completedOrders,
                    // Legacy folded delivered into "completed", overstating the
                    // completed count; keep both, and expose the combined
                    // figure explicitly for the card subtitle.
                    'delivered' => $deliveredOrders,
                    'fulfilled' => $completedOrders + $deliveredOrders,
                ],
                'products' => [
                    'total' => $productQuery->clone()->count(),
                    'active' => $productQuery->clone()->where('status', 'active')->count(),
                    // Digital stock is unlimited, so summing its quantity would
                    // inflate the stock figure the legacy card displayed.
                    'total_stock' => (int) $productQuery->clone()->where('is_digital', false)->sum('quantity'),
                    'low_stock' => $productQuery->clone()
                        ->where('is_digital', false)
                        ->where('status', 'active')
                        ->where('quantity', '>', 0)
                        ->where('quantity', '<=', self::LOW_STOCK_THRESHOLD)
                        ->count(),
                    'out_of_stock' => $productQuery->clone()
                        ->where('is_digital', false)
                        ->where('status', 'active')
                        ->where('quantity', '<=', 0)
                        ->count(),
                ],
                // Unique buyers of this store, not business-wide customers
                // (the old payload reported `customers_count: null`).
                'customers' => [
                    'total' => $orderQuery->clone()->whereNotNull('customer_id')->distinct()->count('customer_id'),
                ],
            ],
            'low_stock' => [
                'threshold' => self::LOW_STOCK_THRESHOLD,
                'items' => $lowStockItems,
                'out_of_stock_count' => $productQuery->clone()
                    ->where('is_digital', false)
                    ->where('status', 'active')
                    ->where('quantity', '<=', 0)
                    ->count(),
            ],
            'recent_orders' => $orderQuery->clone()
                ->with('customer:id,first_name,last_name')
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'customer' => $order->customer?->full_name ?: 'Walk-in',
                    'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
                    'total' => round((float) $order->total, 2),
                    'created_at' => $order->created_at?->toISOString(),
                ])
                ->values()
                ->all(),
            'revenue_series' => $this->revenueSeries($store),
            'pos' => [
                'enabled' => (bool) $store->pos_enabled,
                'active_session' => $activePosSession ? [
                    'id' => $activePosSession->id,
                    'session_code' => $activePosSession->session_code,
                    'opened_by' => $activePosSession->staff?->name,
                    'opened_at' => $activePosSession->opened_at?->toISOString(),
                    'opening_balance' => (int) $activePosSession->opening_balance,
                ] : null,
            ],
            'web' => [
                'has_website' => (bool) $store->has_website,
                'url' => $this->storefrontUrl($store),
                'views' => (int) $store->views,
            ],
        ]);
    }

    /**
     * Confirmed revenue attributed to this store, optionally within a window.
     * Transactions reach a store through their order or their invoice.
     */
    private function confirmedRevenue(Store $store, ?Carbon $from = null, ?Carbon $to = null): float
    {
        $query = Transaction::query()
            ->where('business_id', $store->business_id)
            ->where('status', TransactionStatus::CONFIRMED)
            ->where(fn ($nested) => $nested
                ->whereHas('order', fn ($orders) => $orders->where('store_id', $store->id))
                ->orWhereHas('invoice', fn ($invoices) => $invoices->where('store_id', $store->id)));

        if ($from !== null && $to !== null) {
            // paid_at is the honest month for a payment; fall back to
            // created_at for the rows that never got stamped.
            $query->whereRaw('COALESCE(paid_at, created_at) BETWEEN ? AND ?', [$from, $to]);
        }

        return round((float) $query->sum('amount'), 2);
    }

    /**
     * Six months of revenue, oldest first, zero-filled so a quiet month draws
     * as zero rather than vanishing from the chart (the legacy series did the
     * same).
     *
     * @return array<int, array{month: string, label: string, total: float}>
     */
    private function revenueSeries(Store $store): array
    {
        $totals = Transaction::query()
            ->where('business_id', $store->business_id)
            ->where('status', TransactionStatus::CONFIRMED)
            ->where(fn ($nested) => $nested
                ->whereHas('order', fn ($orders) => $orders->where('store_id', $store->id))
                ->orWhereHas('invoice', fn ($invoices) => $invoices->where('store_id', $store->id)))
            ->whereRaw('COALESCE(paid_at, created_at) >= ?', [Carbon::now()->subMonths(5)->startOfMonth()])
            ->selectRaw("DATE_FORMAT(COALESCE(paid_at, created_at), '%Y-%m') as month, SUM(amount) as total")
            ->groupBy('month')
            ->pluck('total', 'month');

        $series = [];
        for ($monthsAgo = 5; $monthsAgo >= 0; $monthsAgo--) {
            $month = Carbon::now()->subMonths($monthsAgo);
            $key = $month->format('Y-m');

            $series[] = [
                'month' => $key,
                'label' => $month->format('M'),
                'total' => round((float) ($totals[$key] ?? 0), 2),
            ];
        }

        return $series;
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(Store $store): array
    {
        $store->loadCount(['products', 'orders', 'categories', 'assignedStaff']);

        return [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'slug' => $store->slug,
            'description' => $store->description,
            'status' => $store->status,
            'store_type' => $store->store_type,
            'has_website' => (bool) $store->has_website,
            'pos_enabled' => (bool) $store->pos_enabled,
            'balance' => (int) $store->balance,
            'payment_mode' => $store->payment_mode,
            'logo_url' => $store->logo_path ? asset('storage/'.$store->logo_path) : null,
            // The legacy screens rendered contacts, address and socials; the
            // old `show` payload dropped them, so the shell could never draw a
            // complete header.
            'support_email' => $store->support_email,
            'support_phone' => $store->support_phone,
            'address' => $store->address,
            'physical_address' => $store->physical_address,
            'socials' => [
                'instagram' => $store->instagram_url,
                'facebook' => $store->facebook_url,
                'twitter' => $store->twitter_url,
                'tiktok' => $store->tiktok_url,
            ],
            'currency_symbol' => $store->currency_id
                ? Currency::whereKey($store->currency_id)->value('symbol')
                : null,
            'products_count' => (int) ($store->products_count ?? 0),
            'orders_count' => (int) ($store->orders_count ?? 0),
            'categories_count' => (int) ($store->categories_count ?? 0),
            'customers_count' => Order::query()
                ->where('business_id', $store->business_id)
                ->where('store_id', $store->id)
                ->whereNotNull('customer_id')
                ->distinct()
                ->count('customer_id'),
            'staff_count' => (int) ($store->assigned_staff_count ?? 0),
            'store_url' => $this->storefrontUrl($store),
            'created_at' => $store->created_at?->toISOString(),
        ];
    }

    private function storefrontUrl(Store $store): ?string
    {
        if (! $store->has_website || ! $store->slug) {
            return null;
        }

        return 'https://'.$store->slug.'.'.config('app.main_domain', 'storify.ng');
    }
}
