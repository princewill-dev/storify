<?php

namespace App\Services\Admin;

use App\Models\Vat;
use Illuminate\Support\Facades\DB;

/**
 * WS-12 — platform VAT-rate lifecycle.
 *
 * Every path that changes which rate is active must leave exactly one row
 * flagged active (POS, cart and storefront pricing read the newest active
 * rate). The statements and their order moved here from the controller with
 * the transactions that already wrapped them:
 *
 * - create deactivates every existing rate and then inserts the new active
 *   one (legacy forced the new record active regardless of the modal's
 *   checkbox, and effective_at defaults to now);
 * - update deactivates the other rates first, then writes the row that is
 *   taking over;
 * - the "Disable VAT" action inserts the 0% rate first and then deactivates
 *   the others — the order legacy used on that path, kept unchanged.
 *
 * Refusals and HTTP shape stay in the controller: the platform-admin guard,
 * the "cannot be switched off" and "cannot delete the active rate" refusals,
 * the status codes and the message strings. This layer owns the workflow and
 * the transaction boundary only; the audit `Log::info` calls remain on the
 * controller where they read the request's actor.
 */
final class VatService
{
    /**
     * A new record always becomes the active one.
     *
     * @param  array<string, mixed>  $data  the validated create payload
     */
    public function create(array $data): Vat
    {
        return DB::transaction(function () use ($data) {
            // At most one row is ever active: the previous one is switched off
            // before the replacement is written.
            Vat::query()->update(['active' => false]);

            return Vat::create([
                'percentage' => $data['percentage'],
                'active' => true,
                'effective_at' => $data['effective_at'] ?? now(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data  the validated update payload
     * @param  bool  $activate  the flag the controller resolved (an omitted
     *                          `active` keeps the stored value)
     */
    public function update(Vat $vat, array $data, bool $activate): void
    {
        DB::transaction(function () use ($vat, $data, $activate) {
            if ($activate) {
                Vat::query()->whereKeyNot($vat->getKey())->update(['active' => false]);
            }

            $vat->update([
                'percentage' => $data['percentage'],
                'effective_at' => array_key_exists('effective_at', $data) ? $data['effective_at'] : $vat->effective_at,
                'active' => $activate,
            ]);
        });
    }

    /**
     * The "Disable VAT" action: a 0% record that supersedes every other rate.
     * The legacy toggle only created the row and left the previous rate active
     * too; both writes now share one transaction so the single-active
     * invariant holds on this path as well.
     */
    public function createZeroRate(): Vat
    {
        return DB::transaction(function () {
            $zero = Vat::create([
                'percentage' => 0,
                'active' => true,
                'effective_at' => now(),
            ]);

            Vat::query()->whereKeyNot($zero->getKey())->update(['active' => false]);

            return $zero;
        });
    }
}
