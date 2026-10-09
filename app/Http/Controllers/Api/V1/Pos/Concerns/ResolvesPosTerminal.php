<?php

namespace App\Http\Controllers\Api\V1\Pos\Concerns;

use App\Models\PosSession;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * The two facts every till write needs: whose drawer this is, and whether the
 * person at it authorised the sale.
 *
 * Shared because the sale and the card charge that precedes it must agree on
 * both, and the PIN rule in particular is subtle enough to get wrong twice.
 * The check is conditional on the staff member *having* a PIN: most do not, and
 * a rule that demanded six digits from everyone would lock them out of a till
 * they can sell from today.
 */
trait ResolvesPosTerminal
{
    /**
     * The drawer this staff member has open at this store, if any.
     *
     * Scoped by `staff_id` on purpose: several drawers can be open on one store
     * at once, and a sale belongs to the cashier who rang it up.
     */
    protected function openSessionFor(Store $store, ?User $user): ?PosSession
    {
        if (! $user instanceof User) {
            return null;
        }

        return PosSession::query()
            ->where('store_id', $store->id)
            ->where('staff_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->latest()
            ->first();
    }

    /**
     * Whether the submitted PIN authorises this staff member's sales.
     *
     * True when there is nothing to check — no user, or no PIN on their
     * account — so callers read the same way whether or not a PIN is in use.
     */
    protected function pinIsValid(?User $user, ?string $pin): bool
    {
        if (! $user instanceof User || ! $user->pos_pin) {
            return true;
        }

        return ! empty($pin) && Hash::check($pin, $user->pos_pin);
    }
}
