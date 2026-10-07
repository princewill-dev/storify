<?php

namespace App\Repositories\Pos;

use App\Models\PosSession;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The POS terminal's cash-register lookups (legacy `Pos\SessionController`).
 *
 * One predicate — store + signed-in cashier + open status — answers all three
 * call sites (status, open's duplicate guard and close), and it is
 * tenancy-scoped twice over: `store_id` keeps another store's drawer out of
 * the response and `staff_id` keeps another cashier's. This is the legacy
 * guard the management console documents as per cashier, not per store — two
 * cashiers may legitimately hold sessions on one shop floor — so `staff_id`
 * must not be dropped to "simplify" it.
 *
 * `latest()` is what status and close always rendered when more than one open
 * row slipped through; `hasOpenFor()` answers with `exists()` exactly as the
 * open guard did, without materialising the row.
 *
 * Reads and query composition only: no transactions, no abort(), no writes —
 * creating the row and the close reconciliation stay in the controller and on
 * PosSession::close() as they were.
 */
final class PosSessionRepository
{
    /**
     * The cashier's newest open session on the store, or null when none is
     * open. Backs the status payload and the close lookup.
     */
    public function findOpenFor(Store $store, User $user): ?PosSession
    {
        return $this->openFor($store, $user)->latest()->first();
    }

    /**
     * Whether the cashier already holds an open session on the store — the
     * duplicate-open guard's `exists()` query.
     */
    public function hasOpenFor(Store $store, User $user): bool
    {
        return $this->openFor($store, $user)->exists();
    }

    /**
     * @return Builder<PosSession>
     */
    private function openFor(Store $store, User $user): Builder
    {
        return PosSession::query()
            ->where('store_id', $store->id)
            ->where('staff_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN);
    }
}
