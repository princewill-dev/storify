<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\StoreWebMetricsRequest;
use App\Http\Resources\Management\StoreWebMetrics\RecentActivityResource;
use App\Http\Resources\Management\StoreWebMetrics\StoreResource;
use App\Http\Resources\Management\StoreWebMetrics\TopProductResource;
use App\Http\Resources\Management\StoreWebMetrics\WebOrderSeriesResource;
use App\Models\Store;
use App\Repositories\Management\StoreWebMetricsRepository;
use Illuminate\Http\JsonResponse;
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
 * The layers under that read model: the date range validates in
 * StoreWebMetricsRequest, every read and aggregate — the tiles, the chart's
 * grouped counts, top products and the activity feed, with the source
 * definitions and tenancy scope they carry — lives in
 * App\Repositories\Management\StoreWebMetricsRepository, and the payload
 * shapes in App\Http\Resources\Management\StoreWebMetrics\*. This controller
 * keeps the HTTP shape only: the 403 access/deleted guards, the 422
 * `no_website` marker, the chart-window policy, the envelope and the payload
 * key order. No service was extracted: this endpoint is read-only — no
 * transaction, no second-table workflow, no ledger/mail/notification.
 *
 * Deliberate departure from legacy that stays here: a store without
 * `has_website` is refused with 422 + a machine-readable `no_website` marker
 * instead of the legacy redirect+flash, so the SPA renders an
 * enable-storefront state rather than bouncing the person out.
 *
 * Known, accepted consequence of the FormRequest extraction: the rules now
 * run during parameter resolution, so a request that is both malformed and
 * unauthorised answers 422 before the store guard can answer 403. A valid
 * payload from an unauthorised caller still gets 403, and route-binding 404
 * still precedes both — no privilege escalation.
 */
class StoreWebMetricsController extends ApiController
{
    use ResolvesManagementContext;

    /** The legacy chart window, kept as the default when no range is asked for. */
    private const CHART_MONTHS = 6;

    /** Ranges up to this length draw daily bars; longer ranges draw months. */
    private const DAILY_BUCKET_MAX_DAYS = 62;

    public function __construct(private readonly StoreWebMetricsRepository $metrics) {}

    /**
     * GET /management/stores/{store}/web-metrics
     */
    public function show(StoreWebMetricsRequest $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        // Deleted stores leaked back into every store read in legacy; the
        // store shell (WS-03) and tab strip (WS-27) refuse them, and so does
        // this page.
        if ($store->status === Store::STATUS_DELETED) {
            abort(403, 'This store has been deleted.');
        }

        $filters = $request->validated();

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
        $orderTotals = $this->metrics->webOrderTotals($store, $from, $to);
        $webRevenueKobo = $this->metrics->webRevenueKobo($store, $from, $to);

        // The chart's window: the requested range when there is one, otherwise
        // the legacy six-month default.
        if ($from !== null && $to !== null) {
            $chartFrom = $from->copy();
            $chartTo = $to->copy();
        } else {
            $chartFrom = Carbon::now()->subMonths(self::CHART_MONTHS - 1)->startOfMonth();
            $chartTo = Carbon::now();
        }

        $bucket = $chartFrom->diffInDays($chartTo) <= self::DAILY_BUCKET_MAX_DAYS ? 'day' : 'month';

        [$series, $chart] = WebOrderSeriesResource::from(
            $this->metrics->webOrderCountsByBucket($store, $chartFrom, $chartTo, $bucket),
            $chartFrom,
            $chartTo,
            $bucket,
        );

        return $this->ok([
            'store' => (new StoreResource($store))->resolve($request),
            'range' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'is_custom' => $from !== null,
                'chart' => $chart,
            ],
            'metrics' => [
                'store_views' => (int) $store->views,
                'product_views' => $this->metrics->productViews($store),
                'web_orders' => $orderTotals['count'],
                'web_revenue_kobo' => $webRevenueKobo,
                // Integer division: an average is a money figure, so it stays
                // in kobo and never touches float arithmetic.
                'average_order_value_kobo' => $orderTotals['count'] > 0
                    ? intdiv($orderTotals['value_kobo'], $orderTotals['count'])
                    : null,
            ],
            'web_orders_series' => $series,
            'top_products' => TopProductResource::collection($this->metrics->topProducts($store))->resolve($request),
            'recent_activity' => RecentActivityResource::collection($this->metrics->recentActivity($store))->resolve($request),
        ]);
    }
}
