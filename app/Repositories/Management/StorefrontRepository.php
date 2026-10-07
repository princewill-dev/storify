<?php

namespace App\Repositories\Management;

use App\Models\DeliveryRoute;
use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;

/**
 * WS-05 — reads behind the storefront overview, detail and slug check.
 *
 * The tenant-scoped store query, the overview stats and filters, the
 * nation-wide delivery route lookups and the slug check's availability walk
 * live here. Query building only: nothing in this layer aborts an HTTP
 * request — the controller decides what a refused access means.
 *
 * The nation-wide route identity is declared once here and consumed by
 * StorefrontService's write, so the route the storefront checkout reads can
 * never drift from the one the enable workflow upserts.
 */
final class StorefrontRepository
{
    /** The single delivery route the storefront checkout charges. */
    public const NATIONWIDE_STATE = 'All States';

    public const NATIONWIDE_COUNTRY = 'Nigeria';

    /**
     * A fresh tenant-scoped store query: owned stores for a business owner,
     * assigned stores for restricted staff, business stores for other staff.
     *
     * Each call builds a fresh relation — cloning the user's relation would
     * share one underlying query builder across the three stats counts below.
     *
     * @return Builder<Store>|HasMany<Store>|MorphToMany<Store>
     */
    public function accessibleStoresQuery(User $user): Builder|HasMany|MorphToMany
    {
        return $user->accessibleStores()
            ->where('status', '!=', Store::STATUS_DELETED);
    }

    /**
     * The three overview tiles, scoped exactly like the list.
     *
     * @return array{total: int, live: int, offline: int}
     */
    public function stats(User $user): array
    {
        return [
            'total' => $this->accessibleStoresQuery($user)->count(),
            'live' => $this->accessibleStoresQuery($user)->where('has_website', true)->count(),
            'offline' => $this->accessibleStoresQuery($user)->where('has_website', false)->count(),
        ];
    }

    /**
     * The overview list: the search/online-state/status filters, name ordering
     * and pagination. The caller passes the paginator through
     * `paginationMeta()` and the page keeps its query string.
     *
     * @param  array<string, mixed>  $filters  validated by StorefrontIndexRequest
     * @return LengthAwarePaginator<Store>
     */
    public function paginateForUser(User $user, array $filters): LengthAwarePaginator
    {
        return $this->accessibleStoresQuery($user)
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$term}%")
                ->orWhere('slug', 'like', "%{$term}%")
                ->orWhere('store_id', 'like', "%{$term}%")))
            ->when($filters['storefront'] ?? null, fn ($q, $state) => $q->where('has_website', $state === 'live'))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * The nation-wide routes for one page of stores, in a single query keyed
     * by store id — the payload pairs each row with its own route.
     *
     * @param  Collection<int, int|string>  $storeIds
     * @return Collection<int|string, DeliveryRoute>
     */
    public function nationwideRoutesByStore(Collection $storeIds): Collection
    {
        if ($storeIds->isEmpty()) {
            return collect();
        }

        return DeliveryRoute::query()
            ->whereIn('store_id', $storeIds)
            ->where('state', self::NATIONWIDE_STATE)
            ->where('country', self::NATIONWIDE_COUNTRY)
            ->get()
            ->keyBy('store_id');
    }

    /**
     * The single-store shape of the same lookup, for the detail and enable
     * responses.
     */
    public function nationwideRoute(Store $store): ?DeliveryRoute
    {
        return $store->deliveryRoutes()
            ->where('state', self::NATIONWIDE_STATE)
            ->where('country', self::NATIONWIDE_COUNTRY)
            ->first();
    }

    /**
     * The store id an `ignore_store` handle points at, scoped to the caller's
     * accessible stores — a handle from another tenant resolves to null and
     * must not exclude that store's slug from the collision walk.
     */
    public function accessibleStoreIdByPublicId(User $user, string $storeId): ?int
    {
        $id = $user->accessibleStores()
            ->where('store_id', $storeId)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * The debounced availability walk: `-1`, `-2`… past collisions, stopping
     * at the first free slug. Reserved words count as taken even when no store
     * holds them — unlike legacy, which only checked collisions, so "admin"
     * read "available" and then failed validation on submit.
     */
    public function availableSlug(string $base, ?int $ignoreId): string
    {
        $slug = $base;
        $counter = 1;

        while ($this->slugTaken($slug, $ignoreId)) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }

    private function slugTaken(string $slug, ?int $ignoreId): bool
    {
        if (in_array($slug, config('storefront.reserved_subdomains', []), true)) {
            return true;
        }

        return Store::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }
}
