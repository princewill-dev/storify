<?php

namespace App\Repositories\Management;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * WS-04 — query building for the store settings workspace.
 *
 * Three methods earn their place here:
 *  - settingsLoad() is the eager-load set the workspace payload depends on
 *    (the resource reads all of these relations, so they have one definition);
 *  - availableStaff() is an eight-line, business-scoped composition — getting
 *    the business filter wrong leaks staff across tenants;
 *  - uniqueSlug() composes a uniqueness query in a retry loop for the `-1`,
 *    `-2`… contract the storefront wizard shares.
 *
 * The `{charge}` lookups (`$store->serviceCharges()->whereKey(...)`) and the
 * assignedStaff pivot sync stay in the controller: each is a single relation
 * call whose scoping is the point, and wrapping them here would be indirection
 * with no benefit.
 *
 * Reads and writes only: no transactions, no abort() — those live in the
 * service and the controller.
 */
class StoreSettingsRepository
{
    /**
     * The relations the settings payload renders, loaded in the order the
     * controller loaded them (loadMissing keeps an already-loaded
     * activePosSession + staff pair from a second query).
     */
    public function settingsLoad(Store $store): Store
    {
        $store->load(['businessType', 'serviceCharges', 'deliveryRoutes', 'assignedStaff.roles', 'assignedBanks']);
        $store->loadMissing('activePosSession.staff');

        return $store;
    }

    /**
     * Active staff of the business who are not already assigned to the store.
     *
     * @param  Collection<int, int>  $assignedIds
     * @return Collection<int, User>
     */
    public function availableStaff(int $businessId, Collection $assignedIds): Collection
    {
        return User::query()
            ->where('business_id', $businessId)
            ->where('role', 'staff')
            ->where('status', '!=', 'deleted')
            ->whereNotIn('id', $assignedIds)
            ->with('roles')
            ->orderBy('name')
            ->get(['id', 'account_code', 'name', 'email']);
    }

    /**
     * The slug de-duplication the workspace shares with the storefront wizard,
     * `-1`, `-2`… suffixes included. The store being edited is ignored by key
     * so re-submitting (or keeping) its own slug is not a collision; the
     * slug is global, not business-scoped, because storefronts route by
     * subdomain across the whole platform.
     */
    public function uniqueSlug(string $base, Store $store): string
    {
        $slug = $base;
        $counter = 1;

        while (Store::where('slug', $slug)->whereKeyNot($store->getKey())->exists()) {
            $slug = $base.'-'.$counter++;
        }

        return $slug;
    }
}
