<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\ActivityLog;
use App\Models\Currency;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * WS-35 — store web metrics.
 *
 * The legacy "Web Store" tab and standalone `/stores/{store}/web-metrics`
 * page both read `StoreAnalyticsService@web`; this endpoint serves the same
 * four tiles (store views, product views, web orders, web revenue), the
 * six-month web-orders chart, the top-products-by-views list and the store's
 * recent activity feed, with a date range added on top.
 *
 * Sources are the definitions the legacy service used and the new schema
 * still carries:
 *   - store views   → `stores.views` (counter, incremented by the storefront)
 *   - product views → sum of `products.views`
 *   - web orders    → `orders.source = 'checkout'`
 *   - web revenue   → confirmed transactions on checkout orders *or* on the
 *                     store's invoices (the legacy definition; invoices are
 *                     settled off-storefront and were always counted).
 *
 * Deliberate departures from legacy:
 *   - A store without `has_website` is refused with 422 + a machine-readable
 *     `no_website` marker instead of the legacy redirect+flash, so the SPA
 *     renders an enable-storefront state rather than bouncing the person out.
 *   - Money is summed in SQL (the `amount` column is decimal naira) and
 *     converted once at this boundary into integer kobo — the wire format the
 *     rest of the accounting reads use. No float accumulation.
 *   - Every query is scoped to the store's business; the legacy service
 *     filtered invoices by store id alone.
 */
class StoreWebMetricsController extends ApiController
{
    use ResolvesManagementContext;

    /** The source the storefront checkout stamps on orders. */
    private const WEB_ORDER_SOURCE = 'checkout';

    /** The legacy chart window, kept as the default when no range is asked for. */
    private const CHART_MONTHS = 6;

    /** Ranges up to this length draw daily bars; longer ranges draw months. */
    private const DAILY_BUCKET_MAX_DAYS = 62;

    private const TOP_PRODUCTS_LIMIT = 10;

    private const ACTIVITY_LIMIT = 10;

    /**
     * GET /management/stores/{store}/web-metrics
     */
    public function show(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        // Deleted stores leaked back into every store read in legacy; the
        // store shell (WS-03) and tab strip (WS-27) refuse them, and so does
        // this page.
        if ($store->status === Store::STATUS_DELETED) {
            abort(403, 'This store has been deleted.');
        }

        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ]);

        $from = isset($filters['from'])
            ? Carbon::createFromFormat('Y-m-d', $filters['from'])->startOfDay()
            : null;
        $to = isset($filters['to'])
            ? Carbon::createFromFormat('Y-m-d', $filters['to'])->endOfDay()
            : null;

        if (! $store->has_website) {
            return $this->error(
                'This store does not have a web storefront yet. Enable it to start collecting web metrics.',
                422,
                // Machine-readable gate marker: the SPA keys its
                // enable-storefront state off this instead of parsing prose.
                ['store' => ['no_website']],
            );
        }

        // Orders and revenue respect the requested range; the two view
        // counters are lifetime integers with no per-hit timestamps, so they
        // can only ever be reported as all-time (the SPA labels them so).
        $orderQuery = Order::query()
            ->where('business_id', $store->business_id)
            ->where('store_id', $store->id)
            ->where('source', self::WEB_ORDER_SOURCE)
            ->when($from !== null, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('created_at', '<=', $to));

        $webOrders = $orderQuery->clone()->count();

        // Order value in kobo: the column is decimal naira, so the exact SQL
        // sum converts once here — never accumulated in floats.
        $orderValueKobo = (int) round((float) $orderQuery->clone()->sum('total') * 100);

        $revenueQuery = Transaction::query()
            ->where('business_id', $store->business_id)
            ->where('status', TransactionStatus::CONFIRMED)
            ->where(fn ($nested) => $nested
                ->whereHas('order', fn ($orders) => $orders
                    ->where('store_id', $store->id)
                    ->where('source', self::WEB_ORDER_SOURCE))
                ->orWhereHas('invoice', fn ($invoices) => $invoices->where('store_id', $store->id)));

        // paid_at is the honest month for a payment; fall back to created_at
        // for rows that were never stamped (same rule as WS-03's revenue).
        if ($from !== null) {
            $revenueQuery->whereRaw('COALESCE(paid_at, created_at) >= ?', [$from]);
        }
        if ($to !== null) {
            $revenueQuery->whereRaw('COALESCE(paid_at, created_at) <= ?', [$to]);
        }

        $webRevenueKobo = (int) round((float) $revenueQuery->sum('amount') * 100);

        [$series, $chart] = $this->webOrderSeries($store, $from, $to);

        return $this->ok([
            'store' => $this->storePayload($store),
            'range' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'is_custom' => $from !== null,
                'chart' => $chart,
            ],
            'metrics' => [
                'store_views' => (int) $store->views,
                'product_views' => (int) Product::query()
                    ->where('business_id', $store->business_id)
                    ->where('store_id', $store->id)
                    ->sum('views'),
                'web_orders' => $webOrders,
                'web_revenue_kobo' => $webRevenueKobo,
                // Integer division: an average is a money figure, so it stays
                // in kobo and never touches float arithmetic.
                'average_order_value_kobo' => $webOrders > 0 ? intdiv($orderValueKobo, $webOrders) : null,
            ],
            'web_orders_series' => $series,
            'top_products' => $this->topProducts($store),
            'recent_activity' => $this->recentActivity($store),
        ]);
    }

    /**
     * The web-orders chart: zero-filled buckets so a quiet day or month draws
     * as zero instead of vanishing (the legacy six-month series did the same).
     *
     * @return array{0: array<int, array{month: string, label: string, count: int}>, 1: array{from: string, to: string, bucket: string}}
     */
    private function webOrderSeries(Store $store, ?Carbon $from, ?Carbon $to): array
    {
        if ($from !== null && $to !== null) {
            $chartFrom = $from->copy();
            $chartTo = $to->copy();
        } else {
            $chartFrom = Carbon::now()->subMonths(self::CHART_MONTHS - 1)->startOfMonth();
            $chartTo = Carbon::now();
        }

        $bucket = $chartFrom->diffInDays($chartTo) <= self::DAILY_BUCKET_MAX_DAYS ? 'day' : 'month';

        // The bucket key stays a full date string so month labels never
        // collide across a year boundary the way the legacy "May" did.
        $keyExpression = $bucket === 'day'
            ? "DATE_FORMAT(created_at, '%Y-%m-%d')"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        $counts = Order::query()
            ->where('business_id', $store->business_id)
            ->where('store_id', $store->id)
            ->where('source', self::WEB_ORDER_SOURCE)
            ->where('created_at', '>=', $chartFrom)
            ->where('created_at', '<=', $chartTo)
            ->selectRaw("{$keyExpression} as bucket, COUNT(*) as count")
            ->groupBy('bucket')
            ->pluck('count', 'bucket');

        $spansYears = $chartFrom->year !== $chartTo->year;
        $series = [];

        $cursor = $bucket === 'day' ? $chartFrom->copy()->startOfDay() : $chartFrom->copy()->startOfMonth();

        while ($cursor->lessThanOrEqualTo($chartTo)) {
            $key = $cursor->format($bucket === 'day' ? 'Y-m-d' : 'Y-m');

            $series[] = [
                'month' => $key,
                'label' => $cursor->format($this->bucketLabelFormat($bucket, $spansYears)),
                'count' => (int) ($counts[$key] ?? 0),
            ];

            if ($bucket === 'day') {
                $cursor->addDay();
            } else {
                $cursor->addMonthNoOverflow();
            }
        }

        return [
            $series,
            [
                'from' => $chartFrom->toDateString(),
                'to' => $chartTo->toDateString(),
                'bucket' => $bucket,
            ],
        ];
    }

    private function bucketLabelFormat(string $bucket, bool $spansYears): string
    {
        if ($bucket === 'day') {
            return $spansYears ? 'M j, Y' : 'M j';
        }

        return $spansYears ? 'M Y' : 'M';
    }

    /**
     * Top products by views — lifetime counters, so the date range does not
     * apply (a viewed product has no per-view timestamp to filter on).
     *
     * @return array<int, array<string, mixed>>
     */
    private function topProducts(Store $store): array
    {
        return Product::query()
            ->where('business_id', $store->business_id)
            ->where('store_id', $store->id)
            ->orderByDesc('views')
            ->orderBy('name')
            ->limit(self::TOP_PRODUCTS_LIMIT)
            ->get(['id', 'product_code', 'name', 'views', 'status', 'is_digital'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'product_code' => $product->product_code,
                'name' => $product->name,
                'views' => (int) $product->views,
                'status' => $product->status,
                'is_digital' => (bool) $product->is_digital,
            ])
            ->all();
    }

    /**
     * The store's own activity feed: log rows written against this store
     * (storefront visits, store edits), newest first. Scoped to the business
     * as well as the subject so a cross-tenant subject id could never surface.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(Store $store): array
    {
        return ActivityLog::query()
            ->where('business_id', $store->business_id)
            ->where('subject_type', Store::class)
            ->where('subject_id', $store->id)
            ->with('user:id,name')
            ->latest()
            ->limit(self::ACTIVITY_LIMIT)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'user' => $log->user?->name,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(Store $store): array
    {
        return [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'slug' => $store->slug,
            'status' => $store->status,
            'has_website' => (bool) $store->has_website,
            'logo_url' => $store->logo_path ? asset('storage/'.$store->logo_path) : null,
            'currency_symbol' => $store->currency_id
                ? Currency::whereKey($store->currency_id)->value('symbol')
                : null,
            'store_url' => $this->storefrontUrl($store),
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
