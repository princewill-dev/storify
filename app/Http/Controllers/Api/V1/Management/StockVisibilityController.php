<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\StockVisibility\BulkUpdateMinLevelsRequest;
use App\Http\Requests\Management\StockVisibility\StockLevelsRequest;
use App\Http\Requests\Management\StockVisibility\StockLowStockRequest;
use App\Http\Requests\Management\StockVisibility\StockSummaryRequest;
use App\Http\Requests\Management\StockVisibility\UpdateMinLevelRequest;
use App\Http\Resources\Management\StockVisibility\LowStockIndexResource;
use App\Http\Resources\Management\StockVisibility\StockLevelsResource;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\Warehouse;
use App\Repositories\Management\StockVisibilityRepository;
use App\Services\Access\TenantGuard;
use App\Services\Management\StockMinLevelService;
use App\Services\StockVisibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

/**
 * WS-29 — stock visibility and the low-stock model.
 *
 * Legacy's per-location low-stock indicator read `StockLocation.min_quantity`,
 * but no legacy controller or screen ever wrote a non-zero value (verify #5),
 * so the indicator could never fire and the real signal was the dashboard's
 * `Product.quantity <= 10` list. This controller publishes the reconciled
 * definition on every read and — for the first time — lets a business set the
 * min level it is judged against.
 *
 * The row shapes come from {@see StockVisibilityService} so the dashboard
 * summary, the low-stock drill-down and the per-location editor cannot
 * disagree with each other.
 *
 * Layering: the HTTP shape (status codes, message strings, the envelope, the
 * pagination meta), the access guards and the post-write audit logs stay here;
 * the filter rules live in App\Http\Requests\Management\StockVisibility, the
 * query building in App\Repositories\Management\StockVisibilityRepository
 * (which composes on the shared low/out SQL rather than re-deriving it), the
 * two min-level write workflows — with their transaction boundaries and row
 * locks — in App\Services\Management\StockMinLevelService, and the list
 * payloads in App\Http\Resources\Management\StockVisibility. The single-row
 * wrappers (`summary`, `stock_level`, the bulk result) stay in the body: the
 * values are already shaped by the domain service, so a resource would be a
 * pass-through with nothing to shape.
 *
 * Known consequence of the FormRequest extraction (repo-wide, deliberately not
 * worked around): a request that is both malformed and unauthorised now
 * answers 422 where the body guarded first and answered 403. A VALID payload
 * from an unauthorised caller still gets the 403, and route-binding 404 still
 * precedes both, so no privilege is escalated.
 */
class StockVisibilityController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly StockVisibilityService $stock,
        private readonly StockVisibilityRepository $locations,
        private readonly StockMinLevelService $minLevels,
    ) {}

    /**
     * The inventory block the business dashboard renders (audit §3.1): value,
     * units, warehouse/store breakdown, low-stock list and transfer counts.
     */
    public function summary(StockSummaryRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $store = $this->optionalStore($request, $filters['store_id'] ?? null);
        $warehouse = $this->optionalWarehouse($request, $filters['warehouse_id'] ?? null);

        $summary = $this->stock->metrics(
            $this->user($request),
            $store?->id,
            $warehouse?->id,
            (int) ($filters['product_limit'] ?? 6),
        );

        return $this->ok(['summary' => $summary]);
    }

    /**
     * The drill-down behind the dashboard's low-stock list and the warehouse
     * card: one row per stock location in a low/out state, plus a fallback row
     * for products with no stock-location row in scope (legacy tracked stock
     * straight on the product — WS-15's create grid falls back the same way).
     */
    public function lowStock(StockLowStockRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $filters = $request->validated();

        $store = $this->optionalStore($request, $filters['store_id'] ?? null);
        $warehouse = $this->optionalWarehouse($request, $filters['warehouse_id'] ?? null);
        $product = isset($filters['product_id']) ? $this->resolveProduct($request, (int) $filters['product_id']) : null;
        $state = $filters['state'] ?? 'attention';
        $term = $filters['q'] ?? null;
        $includeFallback = (bool) ($filters['include_product_fallback'] ?? true);

        // The scope query, without the state filter: the tab counts are read
        // off it so selecting "out of stock" does not blank the low tab.
        $base = $this->locations->locationQuery($user, $store?->id, $warehouse?->id, $product, $term);

        $lowLocations = $this->locations->countLocations($base, StockVisibilityService::STATE_LOW);
        $outLocations = $this->locations->countLocations($base, StockVisibilityService::STATE_OUT);

        // Display rows for the selected tab.
        $locationRows = $this->locations->displayLocations($base, $state)
            ->map(fn (StockLocation $row) => $this->stock->locationRow($row));

        $productRows = collect();
        $lowFallback = 0;
        $outFallback = 0;

        if ($includeFallback) {
            $lowFallback = $this->locations->countFallbackProducts($user, $store?->id, $warehouse?->id, $product, $term, StockVisibilityService::STATE_LOW);
            $outFallback = $this->locations->countFallbackProducts($user, $store?->id, $warehouse?->id, $product, $term, StockVisibilityService::STATE_OUT);

            $productRows = $this->locations->fallbackProducts($user, $store?->id, $warehouse?->id, $product, $term, $state)
                ->map(fn (Product $row) => $this->stock->productFallbackRow($row));
        }

        // Out rows first, then the lowest quantity, then by name — the
        // pre-refactor ordering, applied before the page is sliced.
        $rows = $locationRows
            ->concat($productRows)
            ->sortBy(fn (array $row) => [$row['out'] ? 0 : 1, (int) $row['quantity'], (string) ($row['product']['name'] ?? '')])
            ->values();

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);
        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values()->all(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $options = $this->locations->filterLocations($user);

        return $this->ok(
            (new LowStockIndexResource(
                rows: $paginator->items(),
                lowLocationCount: $lowLocations,
                outLocationCount: $outLocations,
                lowProductCount: $lowFallback,
                outProductCount: $outFallback,
                definition: $this->stock->definition(),
                warehouses: $options['warehouses'],
                stores: $options['stores'],
                states: $this->stock->states(),
            ))->resolve($request),
            null,
            200,
            $this->paginationMeta($paginator),
        );
    }

    /**
     * Every stock location in scope with its min level — the data behind the
     * min-level editor. WS-15's `stock-locations` read is scoped to one
     * location and feeds the transfer grid; this one is cross-location and
     * filterable by state, which is what the editor and the badges need.
     */
    public function levels(StockLevelsRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $filters = $request->validated();

        $store = $this->optionalStore($request, $filters['store_id'] ?? null);
        $warehouse = $this->optionalWarehouse($request, $filters['warehouse_id'] ?? null);
        $product = isset($filters['product_id']) ? $this->resolveProduct($request, (int) $filters['product_id']) : null;
        $state = $filters['state'] ?? 'all';

        $base = $this->locations->locationQuery($user, $store?->id, $warehouse?->id, $product, $filters['q'] ?? null);

        $counts = $this->locations->locationCounts($base);

        $levels = $this->locations->paginateLevels(
            $base,
            $state,
            $filters['sort'] ?? null,
            (int) ($filters['per_page'] ?? 25),
        );

        $options = $this->locations->filterLocations($user);

        return $this->ok(
            (new StockLevelsResource(
                rows: $levels->getCollection()->map(fn (StockLocation $row) => $this->stock->locationRow($row))->all(),
                counts: $counts,
                definition: $this->stock->definition(),
                warehouses: $options['warehouses'],
                stores: $options['stores'],
                states: $this->stock->states(),
            ))->resolve($request),
            null,
            200,
            $this->paginationMeta($levels),
        );
    }

    /**
     * The min-level editor's save path. Legacy hard-coded `min_quantity => 0`
     * on every write, which is why its low-stock card was always zero; this is
     * the missing write (inventory audit §3.2 / verify #5).
     *
     * `$stockLocation` must keep matching the route's `{stockLocation}`
     * parameter, or implicit model binding is skipped and the container hands
     * in an empty model.
     */
    public function updateMinLevel(UpdateMinLevelRequest $request, StockLocation $stockLocation): JsonResponse
    {
        $this->authorizeLocation($request, $stockLocation);

        $data = $request->validated();

        $this->minLevels->updateMinLevel($stockLocation, (int) $data['min_quantity']);

        Log::info('api.management.stock_min_level_updated', [
            'user_id' => $this->user($request)->id,
            'stock_location_id' => $stockLocation->id,
            'min_quantity' => (int) $data['min_quantity'],
        ]);

        $stockLocation->refresh()->load(['product.images', 'productVariant:id,variant_code', 'locationable']);

        return $this->ok(['stock_level' => $this->stock->locationRow($stockLocation)], 'Minimum level updated.');
    }

    /**
     * Bulk counterpart for the editor's grid — all-or-nothing, so a save can
     * never half-apply a restock plan.
     */
    public function bulkUpdateMinLevels(BulkUpdateMinLevelsRequest $request): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validated();

        $levels = collect($data['items'])->keyBy('id');
        $ids = $levels->keys()->all();

        // Check every id before writing a single row: tenant and location
        // access are both re-derived from the authenticated user, never from
        // the request payload.
        $allowed = $this->locations->accessibleLocationIds($user, $ids);

        if ($allowed->count() !== count($ids)) {
            abort(403, 'One or more stock locations are not available to you.');
        }

        $this->minLevels->updateMinLevels($levels);

        Log::info('api.management.stock_min_levels_updated', [
            'user_id' => $user->id,
            'stock_location_ids' => $ids,
        ]);

        $rows = $this->locations->loadByIdsForDisplay($ids)
            ->map(fn (StockLocation $location) => $this->stock->locationRow($location))
            ->all();

        return $this->ok([
            'updated' => count($rows),
            'stock_levels' => $rows,
        ], 'Minimum levels updated.');
    }

    /**
     * The per-location access guard, unchanged in effect: the row must belong
     * to the caller's business AND sit inside the locations they can reach.
     * The business half goes through the shared TenantGuard; the morphed
     * store/warehouse half is a shape TenantGuard does not express, so it
     * stays a repository predicate. Both refusals keep the pre-refactor 403
     * and message.
     */
    private function authorizeLocation(Request $request, StockLocation $location): void
    {
        $user = $this->user($request);

        app(TenantGuard::class)->authorizeBusiness($location, $user, 'You do not have access to this stock location.');

        if (! $this->locations->isAccessibleTo($user, $location)) {
            abort(403, 'You do not have access to this stock location.');
        }
    }

    /**
     * Accepts a numeric id or the public code, and only ever resolves inside
     * the user's accessible set — a foreign id is a 403, not an empty list.
     */
    private function optionalWarehouse(Request $request, mixed $value): ?Warehouse
    {
        if ($value === null || $value === '') {
            return null;
        }

        $warehouse = $this->locations->findAccessibleWarehouse($this->user($request), $value);

        if (! $warehouse) {
            abort(403, 'You do not have access to this warehouse.');
        }

        return $warehouse;
    }

    private function optionalStore(Request $request, mixed $value): ?Store
    {
        if ($value === null || $value === '') {
            return null;
        }

        $store = $this->locations->findAccessibleStore($this->user($request), $value);

        if (! $store) {
            abort(403, 'You do not have access to this store.');
        }

        return $store;
    }

    private function resolveProduct(Request $request, int $id): Product
    {
        $product = $this->locations->findBusinessProduct($this->user($request), $id);

        if (! $product) {
            abort(403, 'You do not have access to this product.');
        }

        return $product;
    }
}
