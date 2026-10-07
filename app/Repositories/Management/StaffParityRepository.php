<?php

namespace App\Repositories\Management;

use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * WS-20 — query building for the staff directory and the invite form options.
 *
 * The directory scope (business, owner plus staff rows, deactivated rows
 * excluded, optional store filter), its q/status filters, the status counters
 * and the business-scoped role list the options endpoint renders all live
 * here. Reads and query building only: the transaction boundaries belong to
 * StaffParityService and the abort()/404 calls to StaffParityController.
 */
final class StaffParityRepository
{
    /**
     * The filtered, newest-first directory page. The caller paginates it and
     * applies withQueryString(), exactly as the controller always did.
     *
     * @param  array<string, mixed>  $filters  validated by StaffParityIndexRequest
     * @return Builder<User>
     */
    public function listQuery(User $user, ?Store $store, array $filters): Builder
    {
        return $this->directoryQuery($user, $store)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, function ($q) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $q->where(fn ($inner) => $inner->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->withCount(['assignedStores as stores_count', 'assignedWarehouses as warehouses_count'])
            ->with('roles')
            ->orderByRaw('CASE WHEN role = ? THEN 0 ELSE 1 END', [User::ROLE_BUSINESS_OWNER])
            ->latest();
    }

    /**
     * Counts cover the scope (store filter applied) but ignore the q/status
     * filters, so the status tabs keep showing every status' size.
     *
     * @return Collection<string, int>
     */
    public function statusCounts(User $user, ?Store $store): Collection
    {
        return $this->directoryQuery($user, $store)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
    }

    /**
     * The business' roles in the name order the invite form has always
     * rendered, with the permission chips eager-loaded. Business-scoped on
     * purpose: a global role list would leak another tenant's role names.
     *
     * @return Collection<int, Role>
     */
    public function teamRoles(User $user): Collection
    {
        return Role::query()
            ->where('business_id', $user->business_id)
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get();
    }

    /**
     * The directory is the whole team: the owner row (badged in the SPA) plus
     * role=staff. Deactivated rows (status=deleted) never appear.
     *
     * @return Builder<User>
     */
    private function directoryQuery(User $user, ?Store $store): Builder
    {
        return User::query()
            ->where('business_id', $user->business_id)
            ->whereIn('role', ['staff', User::ROLE_BUSINESS_OWNER])
            ->where('status', '!=', 'deleted')
            ->when($store, fn ($q) => $q->whereHas('assignedStores', fn ($inner) => $inner->whereKey($store->id)));
    }
}
