<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockVisibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
 */
class StockVisibilityController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(private readonly StockVisibilityService $stock) {}

    /**
     * The inventory block the business dashboard renders (audit §3.1): value,
     * units, warehouse/store breakdown, low-stock list and transfer counts.
     */
    public function summary(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'store_id' => ['nullable', 'string', 'max:64'],
            'warehouse_id' => ['nullable', 'string', 'max:64'],
            'product_limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

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
    public function lowStock(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $filters = $request->validate([
            'store_id' => ['nullable', 'string', 'max:64'],
            'warehouse_id' => ['nullable', 'string', 'max:64'],
            'product_id' => ['nullable', 'integer'],
            'state' => ['nullable', Rule::in([StockVisibilityService::STATE_LOW, StockVisibilityService::STATE_OUT, 'attention'])],
            'q' => ['nullable', 'string', 'max:100'],
            'include_product_fallback' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $store = $this->optionalStore($request, $filters['store_id'] ?? null);
        $warehouse = $this->optionalWarehouse($request, $filters['warehouse_id'] ?? null);
        $product = isset($filters['product_id']) ? $this->resolveProduct($request, (int) $filters['product_id']) : null;
        $state = $filters['state'] ?? 'attention';
        $term = $filters['q'] ?? null;
        $includeFallback = (bool) ($filters['include_product_fallback'] ?? true);

        // The scope query, without the state filter: the tab counts are read
        // off it so selecting "out of stock" does not blank the low tab.
        $base = $this->stock->accessibleLocationQuery($user, $store?->id, $warehouse?->id)
            ->whereHas('product', fn (Builder $query) => $query
                ->where('is_digital', false)
                ->when($product, fn (Builder $inner) => $inner->whereKey($product->id))
                ->when($term, fn (Builder $inner, $search) => $inner->where(fn (Builder $name) => $name
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('product_code', 'like', "%{$search}%"))));

        $lowLocations = $this->countLocations($base, StockVisibilityService::STATE_LOW);
        $outLocations = $this->countLocations($base, StockVisibilityService::STATE_OUT);

        // Display rows for the selected tab.
        $locations = (clone $base)->with(['product.images', 'productVariant:id,variant_code', 'locationable']);
        $this->applyAttentionFilter($locations, $state);

        $locationRows = $locations->get()->map(fn (StockLocation $row) => $this->stock->locationRow($row));

        $productRows = collect();
        $lowFallback = 0;
        $outFallback = 0;

        if ($includeFallback) {
            $lowFallback = $this->fallbackProductQuery($user, $store?->id, $warehouse?->id, StockVisibilityService::STATE_LOW, $product, $term)->count();
            $outFallback = $this->fallbackProductQuery($user, $store?->id, $warehouse?->id, StockVisibilityService::STATE_OUT, $product, $term)->count();

            $productRows = $this->fallbackProductQuery($user, $store?->id, $warehouse?->id, $state, $product, $term)
                ->get()
                ->map(fn (Product $row) => $this->stock->productFallbackRow($row));
        }

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

        $counts = [
            'low_stock' => $lowLocations + $lowFallback,
            'out_of_stock' => $outLocations + $outFallback,
        ];

        return $this->ok(
            [
                'definition' => $this->stock->definition(),
                'counts' => [
                    'attention' => $counts['low_stock'] + $counts['out_of_stock'],
                    'low_stock' => $counts['low_stock'],
                    'out_of_stock' => $counts['out_of_stock'],
                    'location_rows' => $lowLocations + $outLocations,
                    'product_rows' => $lowFallback + $outFallback,
                ],
                'low_stock' => $paginator->items(),
                'filters' => $this->filterOptions($request),
            ],
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
    public function levels(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $filters = $request->validate([
            'store_id' => ['nullable', 'string', 'max:64'],
            'warehouse_id' => ['nullable', 'string', 'max:64'],
            'product_id' => ['nullable', 'integer'],
            'state' => ['nullable', Rule::in([StockVisibilityService::STATE_LOW, StockVisibilityService::STATE_OUT, StockVisibilityService::STATE_OK, 'all'])],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['attention', 'quantity_asc', 'quantity_desc', 'newest'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $store = $this->optionalStore($request, $filters['store_id'] ?? null);
        $warehouse = $this->optionalWarehouse($request, $filters['warehouse_id'] ?? null);
        $product = isset($filters['product_id']) ? $this->resolveProduct($request, (int) $filters['product_id']) : null;
        $state = $filters['state'] ?? 'all';

        $base = $this->stock->accessibleLocationQuery($user, $store?->id, $warehouse?->id)
            ->whereHas('product', fn (Builder $query) => $query
                ->where('is_digital', false)
                ->when($product, fn (Builder $inner) => $inner->whereKey($product->id))
                ->when($filters['q'] ?? null, fn (Builder $inner, $term) => $inner->where(fn (Builder $name) => $name
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('product_code', 'like', "%{$term}%"))));

        $counts = [
            'total' => (clone $base)->count(),
            'low_stock' => $this->countLocations($base, StockVisibilityService::STATE_LOW),
            'out_of_stock' => $this->countLocations($base, StockVisibilityService::STATE_OUT),
        ];

        $query = clone $base;

        if ($state !== 'all') {
            $this->stock->applyLocationState($query, $state);
        }

        $query->with(['product.images', 'productVariant:id,variant_code', 'locationable']);

        match ($filters['sort'] ?? 'attention') {
            'quantity_asc' => $query->orderBy('quantity')->orderBy('id'),
            'quantity_desc' => $query->orderByDesc('quantity')->orderBy('id'),
            'newest' => $query->orderByDesc('updated_at')->orderByDesc('id'),
            default => $query
                ->orderByRaw(
                    'case when quantity <= 0 then 0 when (min_quantity > 0 and quantity <= min_quantity) or (min_quantity <= 0 and quantity <= ?) then 1 else 2 end',
                    [StockVisibilityService::DEFAULT_LOW_STOCK_THRESHOLD],
                )
                ->orderBy('quantity')
                ->orderBy('id'),
        };

        $levels = $query->paginate((int) ($filters['per_page'] ?? 25))->withQueryString();

        return $this->ok(
            [
                'definition' => $this->stock->definition(),
                'counts' => $counts,
                'stock_levels' => $levels->getCollection()->map(fn (StockLocation $row) => $this->stock->locationRow($row))->all(),
                'filters' => $this->filterOptions($request),
            ],
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
    public function updateMinLevel(Request $request, StockLocation $stockLocation): JsonResponse
    {
        $this->authorizeLocation($request, $stockLocation);

        $data = $request->validate([
            'min_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        DB::transaction(function () use ($stockLocation, $data) {
            $locked = StockLocation::query()->lockForUpdate()->findOrFail($stockLocation->id);
            $locked->min_quantity = (int) $data['min_quantity'];
            $locked->save();
        });

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
    public function bulkUpdateMinLevels(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.min_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $levels = collect($data['items'])->keyBy('id');
        $ids = $levels->keys()->all();

        // Check every id before writing a single row: tenant and location
        // access are both re-derived from the authenticated user, never from
        // the request payload.
        $allowed = $this->stock->accessibleLocationQuery($user)
            ->whereIn('stock_locations.id', $ids)
            ->pluck('stock_locations.id');

        if ($allowed->count() !== count($ids)) {
            abort(403, 'One or more stock locations are not available to you.');
        }

        DB::transaction(function () use ($ids, $levels) {
            $locations = StockLocation::query()->whereIn('id', $ids)->lockForUpdate()->get();

            foreach ($locations as $location) {
                $location->min_quantity = (int) $levels[$location->id]['min_quantity'];
                $location->save();
            }
        });

        Log::info('api.management.stock_min_levels_updated', [
            'user_id' => $user->id,
            'stock_location_ids' => $ids,
        ]);

        $rows = StockLocation::query()
            ->whereIn('id', $ids)
            ->with(['product.images', 'productVariant:id,variant_code', 'locationable'])
            ->get()
            ->map(fn (StockLocation $location) => $this->stock->locationRow($location))
            ->all();

        return $this->ok([
            'updated' => count($rows),
            'stock_levels' => $rows,
        ], 'Minimum levels updated.');
    }

    /**
     * "attention" is the union the badges and the dashboard card show: low or
     * out, never both lists drifting apart.
     */
    private function applyAttentionFilter(Builder $query, string $state): void
    {
        if ($state === 'attention') {
            $query->where(function (Builder $group) {
                $group->where(function (Builder $low) {
                    $this->stock->applyLocationState($low, StockVisibilityService::STATE_LOW);
                })->orWhere(function (Builder $out) {
                    $this->stock->applyLocationState($out, StockVisibilityService::STATE_OUT);
                });
            });

            return;
        }

        $this->stock->applyLocationState($query, $state);
    }

    private function countLocations(Builder $base, string $state): int
    {
        $query = clone $base;
        $this->stock->applyLocationState($query, $state);

        return $query->count();
    }

    /**
     * Products without a stock-location row at the locations in scope, in one
     * of the requested states. Bounded by the state filter, so a business with
     * a ledger does not get its whole catalogue back.
     */
    private function fallbackProductQuery(
        User $user,
        ?int $storeId,
        ?int $warehouseId,
        string $state,
        ?Product $product,
        ?string $term,
    ): Builder {
        $covered = $this->stock->accessibleLocationQuery($user, $storeId, $warehouseId)
            ->select('stock_locations.product_id');

        $query = Product::query()
            ->where('business_id', $user->business_id)
            ->whereNotIn('products.id', $covered)
            ->with(['store:id,name,store_id', 'warehouse:id,warehouse_code,name', 'variants:id,product_id,quantity', 'images'])
            ->when($storeId !== null, fn (Builder $inner) => $inner->where('store_id', $storeId))
            ->when($warehouseId !== null, fn (Builder $inner) => $inner->where('warehouse_id', $warehouseId))
            ->when($storeId === null && $warehouseId === null, fn (Builder $inner) => $inner
                ->whereIn('store_id', $this->stock->accessibleStoreIds($user)))
            ->when($product, fn (Builder $inner) => $inner->whereKey($product->id))
            ->when($term, fn (Builder $inner, $search) => $inner->where(fn (Builder $name) => $name
                ->where('name', 'like', "%{$search}%")
                ->orWhere('product_code', 'like', "%{$search}%")));

        if ($state === 'attention') {
            $query->where(function (Builder $group) {
                $group->where(function (Builder $low) {
                    $this->stock->applyProductState($low, StockVisibilityService::STATE_LOW);
                })->orWhere(function (Builder $out) {
                    $this->stock->applyProductState($out, StockVisibilityService::STATE_OUT);
                });
            });
        } else {
            $this->stock->applyProductState($query, $state);
        }

        return $query;
    }

    /**
     * Filter-picker data for the SPA, scoped to the same access set as the
     * rows themselves. Restricted staff only see their assigned locations.
     *
     * @return array<string, mixed>
     */
    private function filterOptions(Request $request): array
    {
        $user = $this->user($request);

        $warehouses = $user->accessibleWarehouses()
            ->where('status', '!=', Warehouse::STATUS_DELETED)
            // Qualified: restricted staff resolve through a morphedByMany whose
            // pivot also has an `id`, so a bare column list is ambiguous.
            ->orderBy('name')
            ->get(['warehouses.id', 'warehouse_code', 'name'])
            ->map(fn (Warehouse $warehouse) => [
                'id' => $warehouse->id,
                'code' => $warehouse->warehouse_code,
                'name' => $warehouse->name,
            ]);

        $stores = $user->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            ->get(['stores.id', 'store_id', 'name'])
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'code' => $store->store_id,
                'name' => $store->name,
            ]);

        return [
            'states' => $this->stock->states(),
            'warehouses' => $warehouses->all(),
            'stores' => $stores->all(),
            'locations' => [
                ...$warehouses->map(fn (array $warehouse) => [
                    'type' => 'warehouse',
                    'id' => $warehouse['id'],
                    'code' => $warehouse['code'],
                    'name' => $warehouse['name'],
                ])->all(),
                ...$stores->map(fn (array $store) => [
                    'type' => 'store',
                    'id' => $store['id'],
                    'code' => $store['code'],
                    'name' => $store['name'],
                ])->all(),
            ],
        ];
    }

    private function authorizeLocation(Request $request, StockLocation $location): void
    {
        $user = $this->user($request);

        $allowed = (int) $location->business_id === (int) $user->business_id
            && $this->stock->accessibleLocationQuery($user)->whereKey($location->getKey())->exists();

        if (! $allowed) {
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

        $query = $this->user($request)->accessibleWarehouses()
            ->where('status', '!=', Warehouse::STATUS_DELETED);

        $warehouse = is_numeric($value)
            ? (clone $query)->whereKey((int) $value)->first()
            : (clone $query)->where('warehouse_code', $value)->first();

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

        $query = $this->user($request)->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED);

        $store = is_numeric($value)
            ? (clone $query)->whereKey((int) $value)->first()
            : (clone $query)->where('store_id', $value)->first();

        if (! $store) {
            abort(403, 'You do not have access to this store.');
        }

        return $store;
    }

    private function resolveProduct(Request $request, int $id): Product
    {
        $product = Product::query()
            ->where('business_id', $this->user($request)->business_id)
            ->whereKey($id)
            ->first();

        if (! $product) {
            abort(403, 'You do not have access to this product.');
        }

        return $product;
    }
}
