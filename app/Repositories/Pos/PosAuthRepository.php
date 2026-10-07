<?php

namespace App\Repositories\Pos;

use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Store scoping and staff-PIN lookups for the POS terminal auth surface.
 *
 * Query composition only: no transactions, no abort(), no HTTP statuses —
 * the controller owns the 403/422 mapping.
 *
 * `accessibleStoresFor()` earns its place three times over (login, me and the
 * PIN switch all render it) and is tenancy-scoped: it is deliberately NOT
 * `User::accessibleStores()`, which the terminal cannot use because it does
 * not filter `pos_enabled` (the terminal must never list a store it cannot
 * open a session on), and because for an owner it returns only stores whose
 * `user_id` is the owner, where the terminal has always served every store of
 * the owner's business.
 *
 * `userCanSwitchToStore()` and `otherStaffWithPin()` earn theirs through
 * scope: a wrong `business_id` in either lets a terminal reach another
 * business's store or test another business's PIN hashes against the
 * submitted PIN.
 */
final class PosAuthRepository
{
    /**
     * The stores a terminal session may show for the user: restricted staff
     * (staff without `transactions view`) see their non-deleted,
     * POS-enabled assignments; everyone else sees the business's
     * non-deleted, POS-enabled stores, and platform admins are not
     * business-scoped at all.
     *
     * Both branches are the controller's private `getAccessibleStores()`
     * verbatim, filters included.
     *
     * @return Collection<int, Store>
     */
    public function accessibleStoresFor(User $user): Collection
    {
        if ($user->isRestrictedStaff()) {
            return $user->assignedStores()
                ->where('pos_enabled', true)
                ->where('status', '!=', 'deleted')
                ->get();
        }

        return Store::query()
            ->where('pos_enabled', true)
            ->where('status', '!=', 'deleted')
            ->when(! $user->isPlatformAdmin(), fn ($query) => $query->where('business_id', $user->business_id))
            ->get();
    }

    /**
     * Whether the user may switch the terminal to the given store.
     *
     * Two acceptance shapes, kept verbatim from the controller: a non-deleted
     * assignment always qualifies, and only then — for callers who are not
     * restricted staff — a POS-enabled, non-deleted store of their own
     * business (any business for a platform admin) does too. Restricted staff
     * get no fallback, so an unassigned store is refused for them even when
     * `pos_enabled`.
     *
     * Returns the raw predicate; the controller turns false into its 403.
     */
    public function userCanSwitchToStore(User $user, int $storeId): bool
    {
        $assigned = $user->assignedStores()
            ->where('stores.id', $storeId)
            ->where('status', '!=', 'deleted')
            ->exists();

        if ($assigned || $user->isRestrictedStaff()) {
            return $assigned;
        }

        return Store::query()
            ->where('id', $storeId)
            ->when(! $user->isPlatformAdmin(), fn ($query) => $query->where('business_id', $user->business_id))
            ->where('pos_enabled', true)
            ->where('status', '!=', 'deleted')
            ->exists();
    }

    /**
     * The other staff accounts of the user's business that hold a POS PIN —
     * the candidate set the PIN switch hashes against.
     *
     * The caller's own account is excluded because it is checked first and
     * never reaches this query. Only `id` and `pos_pin` are selected; the
     * matched account is materialised in full by the service before the
     * response renders it, exactly as the controller's `User::find()` did.
     *
     * @return Collection<int, User>
     */
    public function otherStaffWithPin(User $currentUser): Collection
    {
        return User::query()
            ->where('business_id', $currentUser->business_id)
            ->where('role', 'staff')
            ->where('id', '!=', $currentUser->id)
            ->whereNotNull('pos_pin')
            ->get(['id', 'pos_pin']);
    }
}
