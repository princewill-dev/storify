<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\DashboardWidgetsRequest;
use App\Http\Resources\Management\Dashboard\LowStockProductResource;
use App\Http\Resources\Management\Dashboard\PendingTransferResource;
use App\Http\Resources\Management\Dashboard\PosSessionResource;
use App\Http\Resources\Management\Dashboard\RecentOrderResource;
use App\Http\Resources\Management\Dashboard\RecentStaffResource;
use App\Http\Resources\Management\Dashboard\RecentTransactionResource;
use App\Http\Resources\Management\Dashboard\RevenueSeriesResource;
use App\Http\Resources\Management\Dashboard\WarehouseWidgetResource;
use App\Models\Store;
use App\Models\Warehouse;
use App\Repositories\Management\DashboardWidgetsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * WS-28 — the full business dashboard payload.
 *
 * Legacy `Management\DashboardController@index` compiled ~40 scalars into one
 * Blade page; this endpoint is the same payload for the SPA. It lives beside
 * `DashboardController` rather than inside it because that controller is owned
 * by the shell workstream — every key this endpoint returns is a superset of
 * what that one already returns, so `DashboardView.vue` alone moves over.
 *
 * Behavioural notes, in the order the legacy page rendered them:
 *
 *  - Cards are permission-gated like the Blade `@can` wrappers: an
 *    unpermitted viewer does not receive the metric or the panel at all
 *    (legacy merely hid them, the data was still computed and shipped).
 *  - The store switcher is stateless now: `?store_id=` is validated against
 *    `accessibleStores()` on every request, not stashed in the session. The
 *    legacy switch endpoint trusted `exists:stores,id` before its access
 *    check; an id outside the circle is a 403 here, so ids can't be probed.
 *  - Legacy computed every order/revenue figure from `user_id = acting user`,
 *    which silently blanked a staff member's view of their employer's data.
 *    Everything here is business-scoped and store-scoped like the rest of
 *    this API.
 *  - The legacy transfer table ignored the selected store; it is scoped here.
 *
 * The layer split: `store_id` validation lives in `DashboardWidgetsRequest`,
 * every read and aggregate in `DashboardWidgetsRepository`, row shaping in
 * `App\Http\Resources\Management\Dashboard\*`, and this controller keeps the
 * HTTP shape only — the 403 store guard, the per-card permission gates and
 * the payload key order. No service was extracted: this endpoint is read-only
 * (no transaction, no second-table workflow, no ledger/mail/notification).
 */
class DashboardWidgetsController extends ApiController
{
    use ResolvesManagementContext;

    /**
     * Same threshold as the products list (`ProductListController`), so the
     * dashboard panel, the `/products?low_stock=1` filter and the amber stock
     * badge can never disagree. Legacy's dashboard used `<= 10`, the old API
     * count used 1–5 and the warehouse card uses `min_quantity`; the one
     * definition for product-level low stock is this one. Out-of-stock
     * (`<= 0`) is still reported separately, and the per-location
     * `min_quantity` model stays a warehouse-card indicator (WS-29 owns its
     * editor).
     */
    private const LOW_STOCK_THRESHOLD = 10;

    public function __construct(
        private readonly DashboardWidgetsRepository $dashboard,
    ) {}

    public function index(DashboardWidgetsRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $businessId = $user->business_id;

        // Every scope decision reads from these two lists; nothing below
        // trusts an id from the request.
        $accessibleStoreIds = $this->dashboard->accessibleStoreIds($user);

        $requestedStoreId = $request->filled('store_id') ? (int) $request->integer('store_id') : null;

        if ($requestedStoreId !== null && ! $accessibleStoreIds->contains($requestedStoreId)) {
            abort(403, 'You do not have access to this store.');
        }

        $storeIds = $requestedStoreId === null ? $accessibleStoreIds : collect([$requestedStoreId]);
        $scopedStore = $requestedStoreId === null
            ? null
            : Store::whereKey($requestedStoreId)->first(['id', 'store_id', 'name']);

        $warehouses = $this->dashboard->accessibleWarehouses($user);
        $warehouseIds = $warehouses->pluck('id')->map(fn ($id) => (int) $id);

        $now = Carbon::now();

        $data = [
            'scope' => [
                'store_id' => $requestedStoreId,
                'store' => $scopedStore === null ? null : [
                    'id' => $scopedStore->id,
                    'store_id' => $scopedStore->store_id,
                    'name' => $scopedStore->name,
                ],
            ],
        ];

        // ── Cards ────────────────────────────────────────────────────────────

        $stats = [];

        if ($user->can('transactions view')) {
            $revenue = $this->dashboard->revenueTotals($businessId, $storeIds, $now);

            $stats['revenue'] = [
                'total' => $revenue['total'],
                'this_month' => $revenue['this_month'],
                'last_month' => $revenue['last_month'],
                // Legacy's exact definition: no baseline means "up 100% when
                // anything came in, flat otherwise" rather than a division by
                // zero. The audit confirmed only this card carried a %.
                'change_percent' => $revenue['last_month'] > 0
                    ? round((($revenue['this_month'] - $revenue['last_month']) / $revenue['last_month']) * 100, 1)
                    : ($revenue['this_month'] > 0 ? 100.0 : 0.0),
            ];
        }

        if ($user->can('orders view')) {
            $orders = $this->dashboard->orderStats($businessId, $storeIds, $now);

            $stats['orders'] = [
                'total' => $orders['total'],
                'pending' => $orders['pending'],
                'processing' => $orders['processing'],
                'completed' => $orders['completed'],
                'this_month' => $orders['this_month'],
                'last_month' => $orders['last_month'],
                // Computed for parity with the legacy controller; the verify
                // pass confirmed the Orders card never rendered it, so the
                // SPA deliberately shows "N pending · N this month" instead.
                'change_percent' => $orders['last_month'] > 0
                    ? round((($orders['this_month'] - $orders['last_month']) / $orders['last_month']) * 100, 1)
                    : ($orders['this_month'] > 0 ? 100.0 : 0.0),
            ];
        }

        if ($user->can('customers view')) {
            $stats['customers'] = $this->dashboard->customerStats($businessId, $storeIds, $now);
        }

        if ($user->can('products view')) {
            $stats['products'] = $this->dashboard->productStats($businessId, $storeIds);
            $stats['stock'] = $this->dashboard->stockStats($businessId, $storeIds, $warehouseIds);
        }

        if ($user->can('warehouses view')) {
            $stats['warehouses'] = $this->dashboard->warehouseStats($businessId, $warehouses, $warehouseIds);
        }

        if ($user->can('stores view')) {
            $stores = $this->dashboard->storeStats($storeIds);

            $stats['stores'] = [
                'total' => $stores['total'],
                'active' => $stores['active'],
            ];
            $stats['web_visits'] = $stores['web_visits'];
        }

        if ($user->can('staff view')) {
            $stats['staff'] = $this->dashboard->staffStats($businessId);
        }

        if ($user->can('pos view_history')) {
            $stats['pos'] = $this->dashboard->posStats($businessId, $storeIds);
        }

        $data['stats'] = $stats;

        // ── Panels ───────────────────────────────────────────────────────────

        if ($user->can('orders view')) {
            $data['recent_orders'] = RecentOrderResource::collection(
                $this->dashboard->recentOrders($businessId, $storeIds, 8)
            )->resolve($request);
        }

        if ($user->can('transactions view')) {
            $data['recent_transactions'] = RecentTransactionResource::collection(
                $this->dashboard->recentTransactions($businessId, $storeIds, 5)
            )->resolve($request);

            $data['revenue_series'] = RevenueSeriesResource::from(
                $this->dashboard->revenueSeries($businessId, $storeIds, $now)
            );
        }

        if ($user->can('transfers view')) {
            $data['pending_transfer_count'] = $this->dashboard->pendingTransferCount(
                $businessId, $requestedStoreId, $storeIds, $warehouseIds
            );

            $data['transfer_list'] = PendingTransferResource::collection(
                $this->dashboard->recentTransfers($businessId, $requestedStoreId, $storeIds, $warehouseIds, 10)
            )->resolve($request);
        }

        if ($user->can('staff view')) {
            $data['recent_staff'] = RecentStaffResource::collection(
                $this->dashboard->recentStaff($businessId, 5)
            )->resolve($request);
        }

        if ($user->can('warehouses view')) {
            $productCounts = $this->dashboard->stockedProductCounts($warehouses);

            $data['warehouses'] = $warehouses
                ->map(fn (Warehouse $warehouse) => (new WarehouseWidgetResource($warehouse, $productCounts[$warehouse->id]))->resolve($request))
                ->values()
                ->all();
        }

        if ($user->can('pos view_history')) {
            $data['pos'] = [
                'open_sessions' => PosSessionResource::collection(
                    $this->dashboard->openPosSessions($businessId, $storeIds)
                )->resolve($request),
            ];
        }

        // The low-stock panel was never permission-gated in legacy and is a
        // dashboard-level alert, so it stays ungated (business- and
        // store-scoped like everything else).
        $lowStock = $this->dashboard->lowStockPanel($businessId, $storeIds, self::LOW_STOCK_THRESHOLD);

        $data['low_stock'] = [
            'threshold' => self::LOW_STOCK_THRESHOLD,
            'items' => LowStockProductResource::collection($lowStock['items'])->resolve($request),
            // Counts include out-of-stock, so they agree with the products
            // list's `low_stock` filter; `out_of_stock_count` splits them out.
            'count' => $lowStock['count'],
            'out_of_stock_count' => $lowStock['out_of_stock_count'],
        ];

        return $this->ok($data);
    }
}
