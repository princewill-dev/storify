<?php

namespace App\Repositories\Management\Product;

use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Query building for the base ProductController's list.
 *
 * Deliberately separate from App\Repositories\Management\ProductRepository
 * (the WS-14 slice behind the shared routes, served by ProductFormController):
 * that one also surfaces store-less rows the business can still reach and
 * carries its own relation set; this one keeps the base slice's behaviour —
 * business scoping plus the caller's accessible stores exactly as
 * User::accessibleStoreIds() returns them, no store-less reachability, and the
 * images-by-position eager load the controller always applied. The two
 * contracts are not interchangeable, so they are not merged.
 *
 * Reads and query composition only — no DB::transaction and no abort() calls
 * in this layer. The list is tenancy-scoped (business AND reachable stores);
 * getting either wrong leaks another tenant's catalogue, which is why the
 * composition lives in one named place.
 */
class ProductRepository
{
    /**
     * The product list: tenant scoping, the store/warehouse/status/category,
     * digital-only and search filters, the position-ordered gallery eager load
     * and the inherited newest-first ordering.
     *
     * The controller reads the request with the same filled()/integer()/
     * string()/boolean() semantics the inline query used and hands over plain
     * values; the index endpoint has no FormRequest on purpose (this slice
     * never validated those filters, and adding rules would turn inputs it used
     * to answer — an out-of-range per_page reaching the paginator — into 422s).
     *
     * @param  array{store_id?: int|null, warehouse_id?: int|null, status?: string|null, category_id?: int|null, digital_only?: bool, q?: string|null, per_page?: int}  $filters
     */
    public function paginateForUser(User $user, array $filters): LengthAwarePaginator
    {
        return Product::query()
            ->where('business_id', $user->business_id)
            ->whereIn('store_id', $user->accessibleStoreIds())
            ->when(($filters['store_id'] ?? null) !== null, fn ($q) => $q->where('store_id', $filters['store_id']))
            ->when(($filters['warehouse_id'] ?? null) !== null, fn ($q) => $q->where('warehouse_id', $filters['warehouse_id']))
            ->when(($filters['status'] ?? null) !== null, fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['category_id'] ?? null) !== null, fn ($q) => $q->where('category_id', $filters['category_id']))
            ->when($filters['digital_only'] ?? false, fn ($q) => $q->where('is_digital', true))
            ->when(($filters['q'] ?? null) !== null, function ($q) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('product_code', 'like', $term)
                    ->orWhere('brand', 'like', $term));
            })
            ->with(['images' => fn ($q) => $q->orderBy('position')])
            ->latest()
            ->paginate($filters['per_page'] ?? 20);
    }
}
