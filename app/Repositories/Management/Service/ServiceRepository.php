<?php

namespace App\Repositories\Management\Service;

use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Query building for the WS-30 ServiceController's list.
 *
 * The store scope arrives already resolved to the caller's accessible,
 * non-deleted store ids (ServiceController::accessibleStoreIds()) rather than
 * re-derived here from the user: the same deleted-store exclusion gates the
 * controller's per-service 403, and deriving it once keeps list and guard in
 * step. (User::accessibleStoreIds() is deliberately not used — it includes
 * deleted stores, which this slice's legacy-parity list must not surface.)
 *
 * Reads and query composition only — no DB::transaction and no abort() calls
 * in this layer. The list is tenancy-scoped; getting the store scope wrong
 * leaks another business's catalogue, which is why the composition lives in
 * one named place.
 */
class ServiceRepository
{
    /**
     * The service list: tenant scoping, the store/status/search filters, the
     * eager loads the inline query applied and the inherited newest-first
     * ordering.
     *
     * The `when()` guards stay truthiness-based exactly as the inline query
     * had them, so a `store_id` of 0 or a blank `q` still skips its filter,
     * and the search keeps the inherited `trim()` over `name` and
     * `service_code`.
     *
     * @param  Collection<int, int>  $storeIds
     * @param  array{store_id?: mixed, status?: mixed, q?: mixed, per_page?: mixed}  $filters  the validated index payload
     */
    public function paginateForStores(Collection $storeIds, array $filters): LengthAwarePaginator
    {
        return Service::query()
            ->whereIn('store_id', $storeIds)
            ->with(['store', 'images', 'currency'])
            ->when($filters['store_id'] ?? null, fn ($query, $storeId) => $query->where('store_id', (int) $storeId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, function ($query, $term) {
                $term = trim((string) $term);
                $query->where(fn ($inner) => $inner
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('service_code', 'like', "%{$term}%"));
            })
            ->latest()
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }
}
