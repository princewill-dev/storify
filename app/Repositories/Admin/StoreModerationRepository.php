<?php

namespace App\Repositories\Admin;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Category;
use App\Models\Order;
use App\Models\OwnershipType;
use App\Models\Pack;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * WS-6 (admin console) — store directory and lifecycle queries.
 *
 * This layer builds queries and applies single-model persists; it never opens
 * a transaction and never calls abort() — transaction boundaries belong to
 * StoreLifecycleService, and the controller owns the HTTP status each guard
 * refusal maps to. Database-level guards (the deleted/incomplete-orders/
 * incomplete-transactions checks) live here so the service and the controller
 * ask the same question of the same query.
 */
final class StoreModerationRepository
{
    /**
     * The directory. Adds the legacy filter set the audit flagged as missing
     * over the previous endpoint: created-date range, the working `deleted`
     * option, `q` over store id and owner (legacy's placeholder promised those
     * and only name worked), plus the enriched scanning columns (logo, owner,
     * business code, business type, main badge, shop link).
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForDirectory(array $filters): LengthAwarePaginator
    {
        $query = Store::query()
            ->with([
                'user:id,name,email,phone',
                'business:id,name,business_code,user_id',
                'ownershipType:id,name',
                'businessType:id,name',
            ])
            ->withCount(['products', 'orders']);

        // Legacy default: deleted rows stay out of the directory. The legacy
        // filter option for them was dead because the exclusion ran first;
        // asking for the status explicitly now returns them.
        $status = $filters['status'] ?? null;

        if ($status !== null) {
            $query->where('status', $status);
        } elseif (! ($filters['include_deleted'] ?? false)) {
            $query->where('status', '!=', Store::STATUS_DELETED);
        }

        if (($filters['q'] ?? null) !== null && $filters['q'] !== '') {
            $term = '%'.trim($filters['q']).'%';

            $query->where(fn ($inner) => $inner
                ->where('name', 'like', $term)
                ->orWhere('store_id', 'like', $term)
                ->orWhere('slug', 'like', $term)
                ->orWhereHas('user', fn ($owner) => $owner
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term))
                ->orWhereHas('business', fn ($business) => $business
                    ->where('name', 'like', $term)
                    ->orWhere('business_code', 'like', $term)));
        }

        if (($filters['ownership_type_id'] ?? null) !== null) {
            $query->where('ownership_type_id', $filters['ownership_type_id']);
        }

        if (($filters['business_type_id'] ?? null) !== null) {
            $query->where('business_type_id', $filters['business_type_id']);
        }

        if (($filters['is_main'] ?? false) === true) {
            $mainStoreId = $this->mainStoreId();

            $query->when($mainStoreId !== null, fn ($inner) => $inner->whereKey($mainStoreId))
                ->when($mainStoreId === null, fn ($inner) => $inner->whereRaw('1 = 0'));
        }

        if (($filters['from'] ?? null) !== null) {
            $query->where('created_at', '>=', $filters['from'].' 00:00:00');
        }

        if (($filters['to'] ?? null) !== null) {
            $query->where('created_at', '<=', $filters['to'].' 23:59:59');
        }

        // Whitelisted in ListStoresRequest — never pass a request-supplied
        // column to orderBy.
        $query->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc');

        return $query->paginate($filters['per_page'] ?? 20)->withQueryString();
    }

    /**
     * The homepage store, resolved from the platform setting WS2 exposes.
     * The controller memoises this per request; the service reads it per
     * workflow.
     */
    public function mainStoreId(): ?int
    {
        $value = Setting::query()->value('main_store_id');

        return $value !== null ? (int) $value : null;
    }

    /**
     * Legacy's bootstrap write: the first store a superadmin creates becomes
     * the homepage store (only when none is configured yet).
     */
    public function configureMainStore(int $storeId): void
    {
        $settings = Setting::query()->first() ?? new Setting;
        $settings->main_store_id = $storeId;
        $settings->save();
    }

    /**
     * The chosen business for a provisioned store. A missing row still renders
     * as a 404 (ModelNotFoundException), exactly as the controller's
     * `findOrFail` did before the extraction.
     */
    public function findBusinessOrFail(int $businessId): Business
    {
        return Business::query()->findOrFail($businessId);
    }

    /**
     * The business a store is being re-linked to; a since-deleted business
     * simply leaves the link untouched.
     */
    public function findBusiness(int $businessId): ?Business
    {
        return Business::query()->find($businessId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createStore(array $attributes): Store
    {
        return Store::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateStore(Store $store, array $attributes): void
    {
        $store->update($attributes);
    }

    /**
     * Normalise and uniquify a slug. Legacy lower-cased and turned spaces into
     * underscores; `Str::slug(..., '_')` keeps that shape while stripping
     * punctuation legacy left in (an apostrophe in a name produced a slug the
     * storefront could never route). Uniqueness retries legacy only ran on
     * update; both paths need it, or a name collision hit the unique index.
     */
    public function uniqueSlug(?string $slug, string $name, ?int $ignoreStoreId = null): string
    {
        $base = Str::slug($slug !== null && trim($slug) !== '' ? $slug : $name, '_');

        if ($base === '') {
            $base = 'store';
        }

        $candidate = $base;
        $suffix = 1;

        while (
            Store::query()
                ->where('slug', $candidate)
                ->when($ignoreStoreId !== null, fn ($query) => $query->whereKeyNot($ignoreStoreId))
                ->exists()
        ) {
            $candidate = $base.'_'.(++$suffix);
        }

        return $candidate;
    }

    /**
     * Delete guard: any order for the store that is not `completed`.
     */
    public function hasIncompleteOrders(Store $store): bool
    {
        return Order::query()
            ->where('store_id', $store->id)
            ->where('status', '!=', OrderStatus::COMPLETED->value)
            ->exists();
    }

    /**
     * Delete guard: any transaction whose order belongs to the store that is
     * not `confirmed`.
     */
    public function hasIncompleteTransactions(Store $store): bool
    {
        return Transaction::query()
            ->whereHas('order', fn ($query) => $query->where('store_id', $store->id))
            ->where('status', '!=', TransactionStatus::CONFIRMED->value)
            ->exists();
    }

    /**
     * The relations the detail console renders. `fresh()` rows arrive without
     * the counts the directory page had, so they are loaded on demand — the
     * same conditional `loadCount` the controller carried.
     */
    public function loadForDetail(Store $store): void
    {
        $store->loadMissing([
            'user:id,name,email,phone',
            'business.owner:id,name,email,phone',
            'ownershipType:id,name',
            'businessType:id,name',
        ]);

        if ($store->products_count === null || $store->orders_count === null) {
            $store->loadCount(['products', 'orders']);
        }
    }

    /**
     * The detail console's panels and metric tiles as raw rows and aggregates,
     * handed to StoreDetailResource to shape. Legacy hard-coded the tiles to
     * zero; they are computed here from confirmed transactions, distinct
     * ordering customers, and completed orders.
     *
     * @return array{
     *     categories: Collection<int, Category>,
     *     recent_products: Collection<int, Product>,
     *     total_earned: mixed,
     *     customers_count: int,
     *     sales_count: int,
     *     packs: Collection<int, Pack>,
     *     packs_count: int
     * }
     */
    public function detailBlocks(Store $store): array
    {
        $categories = $store->categories()->orderBy('name')->get(['id', 'name', 'status']);

        $recentProducts = Product::query()
            ->where('store_id', $store->id)
            ->latest()
            ->take(10)
            ->get(['id', 'product_code', 'name', 'amount', 'status']);

        $totalEarned = Transaction::query()
            ->whereHas('order', fn ($query) => $query->where('store_id', $store->id))
            ->where('status', TransactionStatus::CONFIRMED->value)
            ->sum('amount');

        $customersCount = Order::query()
            ->where('store_id', $store->id)
            ->whereNotNull('customer_id')
            ->distinct()
            ->count('customer_id');

        $salesCount = Order::query()
            ->where('store_id', $store->id)
            ->where('status', OrderStatus::COMPLETED->value)
            ->count();

        // Store has no packs() relation; the panel queries the table the way
        // the legacy controller did.
        $packs = Pack::query()
            ->where('store_id', $store->id)
            ->latest()
            ->take(10)
            ->get(['id', 'pack_code', 'name', 'amount', 'status']);

        return [
            'categories' => $categories,
            'recent_products' => $recentProducts,
            'total_earned' => $totalEarned,
            'customers_count' => $customersCount,
            'sales_count' => $salesCount,
            'packs' => $packs,
            'packs_count' => Pack::query()->where('store_id', $store->id)->count(),
        ];
    }

    /**
     * The create/edit form dropdowns: businesses with their owner, and the two
     * curated type lists.
     *
     * @return array{
     *     businesses: Collection<int, Business>,
     *     ownership_types: Collection<int, OwnershipType>,
     *     business_types: Collection<int, BusinessType>,
     * }
     */
    public function formOptions(): array
    {
        return [
            'businesses' => Business::query()
                ->with('owner:id,name,email')
                ->orderBy('name')
                ->get(),
            'ownership_types' => OwnershipType::query()->orderBy('name')->get(['id', 'name']),
            'business_types' => BusinessType::query()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
