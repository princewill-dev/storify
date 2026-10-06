<?php

namespace App\Repositories\Admin;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\SizeUnit;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\WeightUnit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WS-15 (admin console) — product catalogue queries.
 *
 * This layer builds queries, applies filters/eager loads and owns the
 * single-model persists; it never opens a transaction and never calls
 * abort() — transaction boundaries belong to ProductCatalogueService and the
 * controller owns the HTTP status each refusal maps to. `firstOrFail` is the
 * one framework exception used here because it is exactly what the inline
 * queries did (and what route binding raises), so the 404 stays identical.
 *
 * The catalogue list keeps the legacy filter set: status, q over name / code /
 * store / category, created range, and the URL-only `store_id` scope, which
 * no longer fails open — an unknown store resolves to null and the controller
 * returns an empty page.
 */
final class ProductRepository
{
    /**
     * The platform list, or the store-scoped list when a scope id is passed.
     * `$filters` are validated by ListProductsRequest, which also whitelists
     * the sort column (`sort`/`direction` never arrive unvalidated).
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateCatalogue(array $filters, ?int $storeId, int $perPage): LengthAwarePaginator
    {
        $query = $this->catalogueQuery();

        if ($storeId !== null) {
            $query->where('store_id', $storeId);
        }

        $query
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                $like = '%'.$term.'%';

                $q->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $like)
                    ->orWhere('product_code', 'like', $like)
                    ->orWhereHas('store', fn ($s) => $s->where('name', 'like', $like))
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'like', $like)));
            })
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $categoryId) => $q->where('category_id', $categoryId))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->whereDate('created_at', '<=', $to));

        // Defaults match the legacy list; both columns are whitelisted by the
        // request. `id` breaks ties so paging is stable.
        $sort = $filters['sort'] ?? 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    private function catalogueQuery(): Builder
    {
        return Product::query()->with([
            'store:id,name,store_id,slug',
            'category:id,name',
            'currency:id,code,symbol',
            'images:id,product_id,path,is_primary,position',
            'variants:id,product_id,amount,currency_id,status',
        ]);
    }

    /**
     * Eager-load the relations the list/detail payloads read, so the
     * serializer never lazy-loads per row.
     *
     * @return array<int|string, mixed>
     */
    private function detailRelations(): array
    {
        return [
            'store:id,name,store_id,slug',
            'category:id,name',
            'currency:id,code,symbol',
            'images' => fn ($q) => $q->orderBy('position'),
            'files',
            'variants' => fn ($q) => $q->with(['sizeUnit:id,name', 'weightUnit:id,name'])->orderBy('id'),
        ];
    }

    public function loadForDetail(Product $product): Product
    {
        return $product->load($this->detailRelations());
    }

    /**
     * The legacy store-scoped show — looked up by public product code inside
     * the store, so `/products/{code}` from one store can't resolve another
     * store's product through a copied URL. A miss is the same 404 route
     * binding produces.
     */
    public function findInStoreByCode(Store $store, string $productCode): Product
    {
        return Product::query()
            ->where('store_id', $store->id)
            ->where('product_code', $productCode)
            ->firstOrFail();
    }

    /**
     * A live (not deleted) store for the create/edit payloads.
     */
    public function liveStore(int $id): ?Store
    {
        return Store::query()->whereKey($id)->where('status', '!=', Store::STATUS_DELETED)->first();
    }

    /**
     * A category can only be attached to its own store — legacy accepted any
     * category id, so a product could sit in a category the storefront never
     * rendered. The controller owns the 422 copy.
     */
    public function categoryBelongsToStore(int $categoryId, int $storeId): bool
    {
        return Category::query()->whereKey($categoryId)->where('store_id', $storeId)->exists();
    }

    /**
     * Resolve the legacy `store_id` filter: the numeric id or the public
     * `st_…` id. Null when no such store exists.
     */
    public function resolveStoreId(string $value): ?int
    {
        $store = Store::query()
            ->where('store_id', $value)
            ->when(ctype_digit($value), fn ($q) => $q->orWhere('id', (int) $value))
            ->first(['id']);

        return $store?->id;
    }

    /**
     * The legacy store stock location for a product. `firstOrCreate` keeps
     * the initial-stock path idempotent: a location that already exists is
     * returned untouched, and the ledger write behind it is idempotent per
     * (reference, location) as well.
     */
    public function firstOrCreateStockLocation(Product $product): StockLocation
    {
        return StockLocation::firstOrCreate(
            [
                'product_id' => $product->id,
                'locationable_type' => Store::class,
                'locationable_id' => $product->store_id,
            ],
            [
                'quantity' => 0,
                'min_quantity' => 0,
                'business_id' => $product->business_id,
            ],
        );
    }

    /**
     * The create/edit form dropdowns, raw: stores that still exist, their
     * categories, currencies and the size/weight units. Shaped by
     * ProductFormOptionsResource.
     *
     * @return array{
     *     stores: Collection<int, Store>,
     *     categories: Collection<int, Category>,
     *     currencies: Collection<int, Currency>,
     *     size_units: Collection<int, SizeUnit>,
     *     weight_units: Collection<int, WeightUnit>,
     *     default_currency_id: int|null,
     * }
     */
    public function formOptions(): array
    {
        return [
            'stores' => Store::query()
                ->where('status', '!=', Store::STATUS_DELETED)
                ->with('business:id,name,business_code')
                ->orderBy('name')
                ->get(['id', 'store_id', 'name', 'status', 'business_id']),
            'categories' => Category::query()
                ->orderBy('name')
                ->get(['id', 'name', 'store_id', 'status']),
            'currencies' => Currency::query()
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'symbol', 'is_default']),
            'size_units' => SizeUnit::query()->orderBy('name')->get(['id', 'name', 'code']),
            'weight_units' => WeightUnit::query()->orderBy('name')->get(['id', 'name', 'code']),
            'default_currency_id' => Currency::query()->where('is_default', true)->value('id'),
        ];
    }
}
