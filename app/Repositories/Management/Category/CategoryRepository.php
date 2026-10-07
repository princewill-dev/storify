<?php

namespace App\Repositories\Management\Category;

use App\Models\Category;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Query building for the base CategoryController's list.
 *
 * Deliberately separate from App\Repositories\Management\CategoryRepository
 * (the WS-31 slice behind the shared routes, served by CategoryParityController):
 * that one excludes the categories of deleted stores for every caller, trims
 * the search term and defaults to 20 rows a page; this one keeps the base
 * slice's behaviour — the caller's accessible stores exactly as
 * User::accessibleStoreIds() returns them, no trim, and the per_page it is
 * handed. The two contracts are not interchangeable, so they are not merged.
 *
 * Reads and query composition only — no DB::transaction and no abort() calls
 * in this layer. The list is tenancy-scoped (business AND reachable stores);
 * getting either wrong leaks another tenant's catalogue, which is why the
 * composition lives in one named place.
 */
class CategoryRepository
{
    /**
     * The category list: tenant scoping, the store/name filters, the product
     * count and the inherited name ordering.
     *
     * The controller reads the request with the same filled()/integer()
     * semantics the inline query used and hands over plain values; the index
     * endpoint has no FormRequest on purpose (this slice never validated those
     * filters, and adding rules would turn inputs it used to answer — an
     * out-of-range per_page reaching the paginator — into 422s).
     *
     * @param  array{store_id?: int|null, q?: string|null, per_page?: int}  $filters
     */
    public function paginateForUser(User $user, array $filters): LengthAwarePaginator
    {
        return Category::query()
            ->where('business_id', $user->business_id)
            ->whereIn('store_id', $user->accessibleStoreIds())
            ->when(($filters['store_id'] ?? null) !== null, fn ($q) => $q->where('store_id', $filters['store_id']))
            ->when(($filters['q'] ?? null) !== null, fn ($q) => $q->where('name', 'like', '%'.$filters['q'].'%'))
            ->withCount('products')
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 50);
    }
}
