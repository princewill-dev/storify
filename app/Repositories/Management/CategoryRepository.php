<?php

namespace App\Repositories\Management;

use App\Models\Category;
use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * WS-31 — query building for the categories surface.
 *
 * The list composition (tenancy scoping, the store/name filters, the product
 * count and the inherited name ordering) and the store-id scope the access
 * guards share live here. Reads and query building only: the DB::transaction
 * boundaries, the audit rows and the abort() calls belong to
 * App\Services\Management\CategoryService and the controller.
 */
final class CategoryRepository
{
    /**
     * The categories list.
     *
     * @param  array<string, mixed>  $filters  validated by CategoryIndexRequest
     */
    public function paginateForUser(User $user, array $filters): LengthAwarePaginator
    {
        return Category::query()
            ->where('business_id', $user->business_id)
            ->whereIn('store_id', $this->storeIds($user))
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where('name', 'like', '%'.trim($term).'%'))
            ->withCount('products')
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20);
    }

    /**
     * Accessible stores minus soft-deleted ones — legacy filtered
     * `status != 'deleted'` on the category index; accessibleStores() only
     * applies that for the staff/admin paths, so an owner would otherwise see
     * the categories of a store they deleted.
     *
     * The column is qualified because a restricted staff member's branch is the
     * `staff_assignments` pivot join, which also carries an `id` — an
     * unqualified `pluck('id')` is ambiguous there (SQLSTATE 1052).
     *
     * @return Collection<int, int>
     */
    public function storeIds(User $user): Collection
    {
        return $user->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED)
            ->pluck('stores.id');
    }
}
