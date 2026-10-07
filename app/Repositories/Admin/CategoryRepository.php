<?php

namespace App\Repositories\Admin;

use App\Models\Category;
use App\Models\Store;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * WS-15 (admin console) — category directory queries.
 *
 * This layer builds queries and applies the eager loads; it never opens a
 * transaction and never calls abort() — transaction boundaries belong to
 * CategoryService and the controller owns the HTTP status each refusal maps
 * to. The store lookups mirror ProductRepository's (`liveStore`,
 * `resolveStoreId`) verbatim so both halves of the catalogue resolve tenant
 * scope the same way.
 */
final class CategoryRepository
{
    /**
     * The platform list, or the store-scoped list when a scope id is passed.
     * `$filters` are validated by ListCategoriesRequest, so only `q`/`status`
     * reach the query; the legacy ordering is kept: store, then name.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateDirectory(array $filters, ?int $storeId, int $perPage): LengthAwarePaginator
    {
        $query = Category::query()
            ->with(['store:id,name,store_id', 'parent:id,name'])
            ->withCount('products');

        if ($storeId !== null) {
            $query->where('store_id', $storeId);
        }

        $query
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->where('name', 'like', '%'.$term.'%'))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            // Legacy ordering: store, then name.
            ->orderBy('store_id')->orderBy('name');

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Eager-load the relations the category payload reads — the same set the
     * directory carries — so the post-mutation echo never lazy-loads and its
     * `products_count` matches the list's.
     */
    public function loadForPayload(Category $category): Category
    {
        return $category->load(['store:id,name,store_id', 'parent:id,name'])->loadCount('products');
    }

    /**
     * A live (not deleted) store for the create/edit payloads.
     */
    public function liveStore(int $id): ?Store
    {
        return Store::query()->whereKey($id)->where('status', '!=', Store::STATUS_DELETED)->first();
    }

    /**
     * Resolve the legacy `store_id` filter: the numeric id or the public
     * `st_…` id. Null when no such store exists — the controller turns that
     * into an empty page rather than dropping the filter.
     */
    public function resolveStoreId(string $value): ?int
    {
        $store = Store::query()
            ->where('store_id', $value)
            ->when(ctype_digit($value), fn ($q) => $q->orWhere('id', (int) $value))
            ->first(['id']);

        return $store?->id;
    }
}
