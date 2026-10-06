<?php

namespace App\Services;

use App\Enums\TransferStatus;
use App\Models\Product;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WS-29 — the one place the "is this stock low?" question is answered.
 *
 * Legacy had three answers that disagreed: the product badge rule (amber 1–10,
 * red 0), the dashboard list (`quantity <= 10 && quantity > 0`) and the
 * per-location `StockLocation.min_quantity` check — which no legacy screen
 * could ever set, so it never fired (inventory audit §3.2, verify #5). The
 * rebuild reconciles them here:
 *
 *   - a stock location with an explicit min level is judged against it;
 *   - a location (or a product read on its own quantity) without one falls
 *     back to the default threshold of 10 — the legacy badge/dashboard rule;
 *   - `quantity <= 0` is out of stock whatever the min level says.
 *
 * The stock summary, the low-stock list, the per-location editor and the
 * movement history all resolve through `stateFor()` / the `apply*State`
 * scopes, so the dashboard, warehouse cards and product lists publish the
 * same numbers instead of contradicting each other.
 */
class StockVisibilityService
{
    public const DEFAULT_LOW_STOCK_THRESHOLD = 10;

    public const STATE_OUT = 'out_of_stock';

    public const STATE_LOW = 'low_stock';

    public const STATE_OK = 'in_stock';

    /**
     * The effective threshold for one location: its own min level when set,
     * otherwise the documented default.
     */
    public function threshold(int $minQuantity = 0): int
    {
        return $minQuantity > 0 ? $minQuantity : self::DEFAULT_LOW_STOCK_THRESHOLD;
    }

    public function stateFor(int $quantity, int $minQuantity = 0): string
    {
        if ($quantity <= 0) {
            return self::STATE_OUT;
        }

        return $quantity <= $this->threshold($minQuantity) ? self::STATE_LOW : self::STATE_OK;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function states(): array
    {
        return [
            ['value' => self::STATE_LOW, 'label' => 'Low stock'],
            ['value' => self::STATE_OUT, 'label' => 'Out of stock'],
            ['value' => self::STATE_OK, 'label' => 'In stock'],
        ];
    }

    /**
     * Published with every visibility payload so the SPA can say what "low"
     * means instead of hard-coding a number in a second place.
     *
     * @return array{threshold: int, low_stock: string, out_of_stock: string, per_location: bool}
     */
    public function definition(): array
    {
        return [
            'threshold' => self::DEFAULT_LOW_STOCK_THRESHOLD,
            'low_stock' => 'A location is low when it has a min level set and quantity <= min level, or no min level and quantity <= 10.',
            'out_of_stock' => 'Quantity at or below zero, regardless of any min level.',
            'per_location' => true,
        ];
    }

    /**
     * Stock-location rows in one state, expressed as SQL so counts and lists
     * cannot drift apart. Only adds where-clauses, so callers may nest it.
     */
    public function applyLocationState(Builder $query, string $state): void
    {
        match ($state) {
            self::STATE_OUT => $query->where('quantity', '<=', 0),
            self::STATE_LOW => $query->where('quantity', '>', 0)->where(function (Builder $inner) {
                $inner->where(function (Builder $explicit) {
                    $explicit->where('min_quantity', '>', 0)->whereColumn('quantity', '<=', 'min_quantity');
                })->orWhere(function (Builder $fallback) {
                    $fallback->where('min_quantity', '<=', 0)->where('quantity', '<=', self::DEFAULT_LOW_STOCK_THRESHOLD);
                });
            }),
            default => $query->where('quantity', '>', 0)->where(function (Builder $inner) {
                $inner->where(function (Builder $explicit) {
                    $explicit->where('min_quantity', '>', 0)->whereColumn('quantity', '>', 'min_quantity');
                })->orWhere(function (Builder $fallback) {
                    $fallback->where('min_quantity', '<=', 0)->where('quantity', '>', self::DEFAULT_LOW_STOCK_THRESHOLD);
                });
            }),
        };
    }

    /**
     * Product-level counterpart for catalogue screens that read
     * `Product.quantity` (variant products hold their stock on the variant
     * rows, so their effective stock is the variant sum — the same rule
     * ProductListController publishes).
     */
    public function applyProductState(Builder $query, string $state): void
    {
        $query->where('is_digital', false);

        $effective = '(case when products.has_variants = 1 then '
            .'(select coalesce(sum(quantity), 0) from product_variants where product_variants.product_id = products.id) '
            .'else products.quantity end)';

        match ($state) {
            self::STATE_OUT => $query->whereRaw("{$effective} <= 0"),
            self::STATE_LOW => $query->whereRaw("{$effective} > 0")->whereRaw("{$effective} <= ?", [self::DEFAULT_LOW_STOCK_THRESHOLD]),
            default => $query->whereRaw("{$effective} > ?", [self::DEFAULT_LOW_STOCK_THRESHOLD]),
        };
    }

    /**
     * Effective stock for one product: the variant sum when it is variant
     * driven, otherwise its own quantity.
     */
    public function productStock(Product $product): int
    {
        if ($product->has_variants && $product->relationLoaded('variants')) {
            return (int) $product->variants->sum('quantity');
        }

        return (int) $product->quantity;
    }

    /**
     * Every stock location the user may see — both hosts are checked, so a
     * restricted staff member only sees their assigned stores/warehouses and
     * a deleted host disappears from every read.
     */
    public function accessibleLocationQuery(User $user, ?int $storeId = null, ?int $warehouseId = null): Builder
    {
        $storeIds = $this->accessibleStoreIds($user);
        $warehouseIds = $this->accessibleWarehouseIds($user);

        // A location filter scopes the whole read to that host. Narrowing only
        // its own id list is not enough: filtering by one warehouse would still
        // leave every accessible store's rows in the result (and vice versa).
        // When both are given, both hosts stay in scope — each filter is a
        // location the caller asked for.
        if ($storeId !== null) {
            $storeIds = $storeIds->intersect([$storeId]);

            if ($warehouseId === null) {
                $warehouseIds = collect();
            }
        }

        if ($warehouseId !== null) {
            $warehouseIds = $warehouseIds->intersect([$warehouseId]);

            if ($storeId === null) {
                $storeIds = collect();
            }
        }

        return StockLocation::query()
            ->where('stock_locations.business_id', $user->business_id)
            ->where(function (Builder $query) use ($storeIds, $warehouseIds) {
                $query->where(function (Builder $stores) use ($storeIds) {
                    $stores->where('locationable_type', Store::class)->whereIn('locationable_id', $storeIds);
                })->orWhere(function (Builder $warehouses) use ($warehouseIds) {
                    $warehouses->where('locationable_type', Warehouse::class)->whereIn('locationable_id', $warehouseIds);
                });
            });
    }

    /**
     * @return Collection<int, int>
     */
    public function accessibleStoreIds(User $user): Collection
    {
        return $user->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            // Qualified: restricted staff resolve through a morphedByMany whose
            // pivot also has an `id`, so a bare pluck('id') is ambiguous and
            // the query fails outright.
            ->pluck('stores.id');
    }

    /**
     * @return Collection<int, int>
     */
    public function accessibleWarehouseIds(User $user): Collection
    {
        return $user->accessibleWarehouses()
            ->where('status', '!=', Warehouse::STATUS_DELETED)
            ->pluck('warehouses.id');
    }

    /**
     * The row shape shared by the low-stock list and the min-level editor.
     *
     * @return array<string, mixed>
     */
    public function locationRow(StockLocation $location): array
    {
        $host = $location->locationable;
        $quantity = (int) $location->quantity;
        $minQuantity = (int) $location->min_quantity;
        $state = $this->stateFor($quantity, $minQuantity);

        return [
            'id' => 'location:'.$location->id,
            'source' => 'location',
            'stock_location_id' => $location->id,
            'product' => $this->productRef($location->product),
            'variant_label' => $location->productVariant?->variant_code,
            'store' => $host instanceof Store
                ? ['id' => $host->id, 'store_id' => $host->store_id, 'name' => $host->name]
                : null,
            'warehouse' => $host instanceof Warehouse
                ? ['id' => $host->id, 'warehouse_code' => $host->warehouse_code, 'name' => $host->name]
                : null,
            'location' => $this->locationRef($host),
            'quantity' => $quantity,
            'min_quantity' => $minQuantity,
            'threshold' => $this->threshold($minQuantity),
            'state' => $state,
            'low' => $state === self::STATE_LOW,
            'out' => $state === self::STATE_OUT,
            'updated_at' => $location->updated_at?->toISOString(),
        ];
    }

    /**
     * A product that has no stock-location row at the locations in scope —
     * legacy businesses tracked stock straight on the product, and WS-15's
     * create grid already falls back the same way, so the low-stock list does
     * too instead of hiding rows the product list shows.
     *
     * @return array<string, mixed>
     */
    public function productFallbackRow(Product $product): array
    {
        $quantity = $this->productStock($product);
        $state = $this->stateFor($quantity);

        return [
            'id' => 'product:'.$product->id,
            'source' => 'product',
            'stock_location_id' => null,
            'product' => $this->productRef($product),
            'variant_label' => null,
            'store' => $product->store ? [
                'id' => $product->store->id,
                'store_id' => $product->store->store_id,
                'name' => $product->store->name,
            ] : null,
            'warehouse' => $product->warehouse ? [
                'id' => $product->warehouse->id,
                'warehouse_code' => $product->warehouse->warehouse_code,
                'name' => $product->warehouse->name,
            ] : null,
            'location' => null,
            'quantity' => $quantity,
            'min_quantity' => 0,
            'threshold' => self::DEFAULT_LOW_STOCK_THRESHOLD,
            'state' => $state,
            'low' => $state === self::STATE_LOW,
            'out' => $state === self::STATE_OUT,
            'updated_at' => $product->updated_at?->toISOString(),
        ];
    }

    /**
     * The dashboard's inventory block (inventory audit §3.1): stock value,
     * total units, warehouse/store breakdown, the reconciled low-stock list
     * and the pending/approved transfer counts. WS-28 renders it; it is
     * deliberately one call so the numbers always come from one definition.
     *
     * @return array<string, mixed>
     */
    public function metrics(User $user, ?int $storeId = null, ?int $warehouseId = null, int $productLimit = 6): array
    {
        $base = $this->accessibleLocationQuery($user, $storeId, $warehouseId)
            ->whereHas('product', fn (Builder $query) => $query->where('is_digital', false));

        // Money is summed in integer kobo, rounded per unit, never as floats.
        $totals = (clone $base)
            ->leftJoin('products', 'products.id', '=', 'stock_locations.product_id')
            ->leftJoin('product_variants', 'product_variants.id', '=', 'stock_locations.product_variant_id')
            ->selectRaw('COUNT(*) as location_count')
            ->selectRaw('COALESCE(SUM(stock_locations.quantity), 0) as total_units')
            ->selectRaw('COUNT(DISTINCT stock_locations.product_id) as stocked_products')
            ->selectRaw('COALESCE(SUM(stock_locations.quantity * ROUND(COALESCE(product_variants.amount, products.amount, products.cost_price, 0) * 100)), 0) as value_kobo')
            ->first();

        $warehouses = $this->breakdown(clone $base, Warehouse::class);
        $stores = $this->breakdown(clone $base, Store::class);

        $warehouseRows = Warehouse::query()
            ->whereIn('id', $this->accessibleWarehouseIds($user))
            ->when($warehouseId !== null, fn (Builder $query) => $query->whereKey($warehouseId))
            ->orderBy('name')
            ->get();

        $aggregates = $this->groupedAggregates($base, Warehouse::class);

        $products = $this->productStateQuery($user, $storeId, $warehouseId);

        $lowProducts = clone $products;
        $this->applyProductState($lowProducts, self::STATE_LOW);
        $lowProductCount = $lowProducts->count();

        $outProducts = clone $products;
        $this->applyProductState($outProducts, self::STATE_OUT);
        $outProductCount = $outProducts->count();

        $lowList = (clone $lowProducts)
            ->with(['store:id,name,store_id', 'warehouse:id,warehouse_code,name', 'variants:id,product_id,quantity', 'images'])
            ->orderBy('quantity')
            ->limit($productLimit)
            ->get()
            ->map(fn (Product $product) => $this->productFallbackRow($product))
            ->all();

        $valueKobo = (int) ($totals->value_kobo ?? 0);

        return [
            'definition' => $this->definition(),
            'stock' => [
                'value_kobo' => $valueKobo,
                'value' => $valueKobo / 100,
                'total_units' => (int) ($totals->total_units ?? 0),
                'stocked_products' => (int) ($totals->stocked_products ?? 0),
                'locations' => (int) ($totals->location_count ?? 0),
            ],
            'warehouses' => [
                ...$warehouses,
                'count' => $warehouseRows->count(),
                'rows' => $warehouseRows->map(fn (Warehouse $warehouse) => [
                    'id' => $warehouse->id,
                    'warehouse_code' => $warehouse->warehouse_code,
                    'name' => $warehouse->name,
                    'city' => $warehouse->city,
                    'state' => $warehouse->state,
                    'units' => (int) ($aggregates[$warehouse->id]->units ?? 0),
                    'products' => (int) ($aggregates[$warehouse->id]->products ?? 0),
                    'low_stock_count' => (int) ($aggregates[$warehouse->id]->low_stock ?? 0),
                    'out_of_stock_count' => (int) ($aggregates[$warehouse->id]->out_of_stock ?? 0),
                ])->values()->all(),
            ],
            'stores' => [
                ...$stores,
                'count' => $storeId !== null
                    ? ($this->accessibleStoreIds($user)->contains($storeId) ? 1 : 0)
                    : $this->accessibleStoreIds($user)->count(),
            ],
            'low_stock' => [
                'product_count' => $lowProductCount,
                'out_of_stock_products' => $outProductCount,
                'locations' => $this->countState($base, self::STATE_LOW),
                'out_of_stock_locations' => $this->countState($base, self::STATE_OUT),
                'products' => $lowList,
            ],
            'transfer_requests' => $this->transferCounts($user),
        ];
    }

    /**
     * @return array{units: int, locations: int, low_stock: int, out_of_stock: int}
     */
    private function breakdown(Builder $base, string $class): array
    {
        $type = (clone $base)->where('locationable_type', $class);

        return [
            'units' => (int) (clone $type)->sum('quantity'),
            'locations' => (clone $type)->count(),
            'low_stock' => $this->countState($type, self::STATE_LOW),
            'out_of_stock' => $this->countState($type, self::STATE_OUT),
        ];
    }

    /**
     * Per-warehouse aggregates in two grouped queries instead of a query per
     * warehouse row.
     *
     * @return Collection<int|string, object>
     */
    private function groupedAggregates(Builder $base, string $class): Collection
    {
        $aggregates = (clone $base)
            ->where('locationable_type', $class)
            ->selectRaw('locationable_id, COALESCE(SUM(quantity), 0) as units, COUNT(DISTINCT product_id) as products')
            ->groupBy('locationable_id')
            ->get()
            ->keyBy('locationable_id');

        $low = $this->stateGroup($base, $class, self::STATE_LOW);
        $out = $this->stateGroup($base, $class, self::STATE_OUT);

        return $aggregates->map(function ($row) use ($low, $out) {
            $row->low_stock = (int) ($low[$row->locationable_id]->aggregate ?? 0);
            $row->out_of_stock = (int) ($out[$row->locationable_id]->aggregate ?? 0);

            return $row;
        });
    }

    /**
     * @return Collection<int|string, object>
     */
    private function stateGroup(Builder $base, string $class, string $state): Collection
    {
        $query = (clone $base)->where('locationable_type', $class);
        $this->applyLocationState($query, $state);

        return $query
            ->selectRaw('locationable_id, COUNT(*) as aggregate')
            ->groupBy('locationable_id')
            ->get()
            ->keyBy('locationable_id');
    }

    private function countState(Builder $base, string $state): int
    {
        $query = clone $base;
        $this->applyLocationState($query, $state);

        return $query->count();
    }

    private function productStateQuery(User $user, ?int $storeId, ?int $warehouseId): Builder
    {
        return Product::query()
            ->where('business_id', $user->business_id)
            ->whereIn('store_id', $this->accessibleStoreIds($user))
            ->when($storeId !== null, fn (Builder $query) => $query->where('store_id', $storeId))
            ->when($warehouseId !== null, fn (Builder $query) => $query->where('warehouse_id', $warehouseId));
    }

    /**
     * Pending (incl. awaiting acknowledgement) + approved transfer requests,
     * limited to transfers touching a location the user can see.
     *
     * @return array{pending: int, approved: int}
     */
    private function transferCounts(User $user): array
    {
        $storeIds = $this->accessibleStoreIds($user);
        $warehouseIds = $this->accessibleWarehouseIds($user);

        $query = StockTransfer::query()
            ->where('business_id', $user->business_id)
            ->where(function (Builder $outer) use ($storeIds, $warehouseIds) {
                $outer->where(function (Builder $warehouse) use ($warehouseIds) {
                    $warehouse->where('from_location_type', Warehouse::class)->whereIn('from_location_id', $warehouseIds);
                })->orWhere(function (Builder $warehouse) use ($warehouseIds) {
                    $warehouse->where('to_location_type', Warehouse::class)->whereIn('to_location_id', $warehouseIds);
                })->orWhere(function (Builder $store) use ($storeIds) {
                    $store->where('from_location_type', Store::class)->whereIn('from_location_id', $storeIds);
                })->orWhere(function (Builder $store) use ($storeIds) {
                    $store->where('to_location_type', Store::class)->whereIn('to_location_id', $storeIds);
                });
            });

        return [
            'pending' => (clone $query)->whereIn('status', [
                TransferStatus::PENDING->value,
                TransferStatus::AWAITING_ACKNOWLEDGMENT->value,
            ])->count(),
            'approved' => (clone $query)->where('status', TransferStatus::APPROVED->value)->count(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function productRef(?Product $product): ?array
    {
        if (! $product) {
            return null;
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'product_code' => $product->product_code,
            'store_id' => $product->store_id,
            'warehouse_id' => $product->warehouse_id,
            'is_digital' => (bool) $product->is_digital,
            'has_variants' => (bool) $product->has_variants,
            'image_url' => $product->primaryImage()?->path
                ? asset('storage/'.$product->primaryImage()->path)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function locationRef(?object $host): ?array
    {
        if ($host instanceof Warehouse) {
            return ['type' => 'warehouse', 'id' => $host->id, 'code' => $host->warehouse_code, 'name' => $host->name];
        }

        if ($host instanceof Store) {
            return ['type' => 'store', 'id' => $host->id, 'code' => $host->store_id, 'name' => $host->name];
        }

        return null;
    }
}
