<?php

namespace App\Repositories\Management;

use App\Models\Currency;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * WS-02 — query building for store onboarding: the parity store list, the
 * create form's options, the available-slug walk and the per-store customer
 * aggregates.
 *
 * Reads and query building only: the HTTP contract, the 403/422 refusals and
 * the CreateStore action call belong to StoreOnboardingController. The
 * tenancy-scoped reads (accessible stores, the business' staff and bank
 * pickers, the business-bank and staff-selection checks) are scoped here on
 * purpose — getting one wrong leaks another business's data.
 */
final class StoreOnboardingRepository
{
    /**
     * The relations the list counts and the create/finalize echoes reload,
     * shared so "what the list shows" and "what the echo shows" can never
     * drift apart.
     */
    public const COUNT_RELATIONS = ['products', 'categories', 'orders'];

    /**
     * The filtered, newest-first store list query. The caller paginates it
     * and applies withQueryString(), exactly as the controller always did.
     *
     * @param  array<string, mixed>  $filters  validated by StoreOnboardingIndexRequest
     * @return Builder<Store>
     */
    /**
     * User::accessibleStores() branches by role — an owner gets a HasMany, a
     * restricted staff member a MorphToMany, a platform admin a plain Builder.
     * The declared type has to cover all three; narrowing it to Builder breaks
     * owners at runtime.
     */
    public function listQuery(User $user, array $filters): Builder|HasMany|MorphToMany
    {
        return $user->accessibleStores()
            ->when(
                $filters['status'] ?? null,
                fn ($q, $status) => $q->where('status', $status),
                // Deleted stores never leak back into the default list.
                fn ($q) => $q->where('status', '!=', Store::STATUS_DELETED),
            )
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.$term.'%';

                $q->where(fn ($inner) => $inner
                    ->where('name', 'like', $like)
                    ->orWhere('store_id', 'like', $like));
            })
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->withCount(self::COUNT_RELATIONS)
            ->latest();
    }

    /**
     * The product/category/order counts the create and finalize echoes carry —
     * the same relation set the list counts, loaded on one store.
     */
    public function loadCounts(Store $store): Store
    {
        return $store->loadCount(self::COUNT_RELATIONS);
    }

    /**
     * The distinct buyers behind the orders of one page of stores, counted in
     * one query for the whole page and keyed by store id.
     *
     * The legacy payload read a `customers_count` attribute that no store
     * ever had, so the count always rendered as null; the controller counted
     * the buyers per store inline instead — this is that query, moved here.
     *
     * @param  Collection<int, int|string>  $storeIds
     * @return Collection<int|string, int|string>
     */
    public function customerCountsForStores(Collection $storeIds): Collection
    {
        return Order::query()
            ->whereIn('store_id', $storeIds)
            ->whereNotNull('customer_id')
            ->selectRaw('store_id, count(distinct customer_id) as total')
            ->groupBy('store_id')
            ->pluck('total', 'store_id');
    }

    /**
     * The single-store shape of the same distinct-buyer aggregate, for the
     * finalize screen.
     */
    public function customerCount(Store $store): int
    {
        return (int) $this->customerCountsForStores(collect([$store->getKey()]))->get($store->getKey(), 0);
    }

    /**
     * The create form's picker data: the active staff of the business, its
     * bank accounts and the global currency list.
     *
     * The staff and bank reads are business-scoped on purpose — a picker that
     * read across tenants would leak another business's staff and accounts.
     *
     * @return array{
     *     staff: Collection<int, User>,
     *     banks: Collection<int, StoreBank>,
     *     currencies: Collection<int, Currency>,
     * }
     */
    public function formOptions(User $user): array
    {
        return [
            'staff' => User::query()
                ->where('business_id', $user->business_id)
                ->where('role', 'staff')
                ->where('status', 'active')
                ->with('roles')
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'banks' => StoreBank::query()
                ->where('business_id', $user->business_id)
                ->orderBy('bank_name')
                ->get(),
            'currencies' => Currency::query()
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'symbol']),
        ];
    }

    /**
     * The legacy slug check walked -1, -2… until it found a free name. Keep
     * that contract for slugs generated server-side at create time — the
     * model's creating hook would otherwise randomise the suffix.
     */
    public function availableSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'store';
        }

        $reserved = config('storefront.reserved_subdomains', []);
        $slug = $base;
        $counter = 1;

        while (in_array($slug, $reserved, true) || Store::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }

    /**
     * Whether the bank account belongs to the caller's business — the
     * selection check behind the controller's 422 refusal. Business-scoped on
     * purpose: a foreign id must not attach another business's account.
     */
    public function businessBankExists(User $user, int|string $bankId): bool
    {
        return StoreBank::query()
            ->where('business_id', $user->business_id)
            ->whereKey($bankId)
            ->exists();
    }

    /**
     * How many of the selected ids are staff of the caller's business. The
     * controller compares it against the distinct selection size; a pick from
     * another business must count as invalid.
     *
     * @param  array<int, int>  $staffIds
     */
    public function validStaffCount(User $user, array $staffIds): int
    {
        return User::query()
            ->where('business_id', $user->business_id)
            ->where('role', 'staff')
            ->whereIn('id', $staffIds)
            ->count();
    }
}
