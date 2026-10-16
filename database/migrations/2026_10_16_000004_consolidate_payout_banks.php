<?php

use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Point the businesses the setup step promised a payout account at the account
 * they named.
 *
 * `POST /management/payment-settings/bank-accounts` — the write behind "Connect
 * your bank account. You will receive your payouts here." — set
 * `store_banks.is_primary` and never set `businesses.payout_bank_id`. Every
 * business that named an account in the wizard therefore holds a bank row, a
 * NULL destination, and {@see PayoutSweepService::payoutBlocker()}'s
 * `no_bank`: money collected, maturing into `available_balance`, and never sent.
 * The wallet's amber "Your money is waiting: no payout bank" was the correct
 * description of a business that had already answered the question.
 *
 * The write was fixed in the same release, for the first account only. This
 * fills the NULLs that the fix could not reach back to.
 *
 * ## Why this is a migration and not a `repair:` command
 *
 * The house style for a money repair is a command with a dry run
 * (`payments:backfill-processing-fees`), and that command states its own test:
 * it is a command because it rewrites money columns on rows that are already on
 * the books, "a thing to look at before it happens rather than a thing to
 * discover afterwards". This rewrites no money column. It fills one nullable
 * foreign key that names a row the business already owns, so a dry run would
 * show nothing this body does not already say. What there *is*, is a clock: the
 * sweep runs every five minutes, so every hour between a deploy and somebody
 * remembering to run a command is money the business can see and cannot
 * receive. The precedent is in-band — `2026_10_12_000002`, which added this
 * column, backfilled it from a migration with the same defensive shape.
 *
 * ## Why this is not the derivation that migration refused
 *
 * `2026_10_12_000002` deliberately did not read `is_primary` *as* the pointer,
 * because that flag "drives bank transfer and reconciliation, it is settable by
 * a business that never intended to receive splits there, and a business
 * holding one would silently pass a gate it was never asked about".
 *
 * That was a refusal of **read-time derivation** — `is_payout := is_primary`
 * for every business forever, which a transfer-only account could never escape
 * and no later repoint could unwind. This is a **one-time fill of a NULL**.
 * Afterwards the two columns are independent: the flag still drives bank
 * transfer and reconciliation, the pointer still decides where money is sent,
 * and either moves without the other.
 *
 * The "gate it was never asked about" that migration named no longer exists
 * either. The pointer does not gate selling, and the sweep reads the business's
 * own balance as the proof of collection, so a filled pointer moves nothing for
 * a business that is not holding collected money.
 *
 * What it does do, stated plainly rather than left to be discovered: a business
 * holding a balance with `auto_payout` on will be paid on the next run, to the
 * account this picks. That is the repair working, not a side effect of it — and
 * the business can repoint it at another of its own accounts afterwards.
 *
 * ## Which account
 *
 * Primary first, then oldest. The setup step's row *is* the primary one, so for
 * most of the businesses this exists for there is exactly one candidate and the
 * ordering never decides anything. Where it does:
 *
 *   - `is_primary DESC` first, because a later `PATCH .../bank-accounts/{id}/primary`
 *     is the business's own most recent statement about which account is its
 *     default.
 *   - `id ASC` as the tiebreak rather than `DESC`: the oldest row is the one the
 *     business added first, and therefore the closest to what it named. (Note
 *     `PaymentGatewayService::bankIdFor()` uses `id DESC`, which is right there —
 *     matching typed details back to the newest row holding them — and wrong
 *     here.)
 *
 * `is_verified` is deliberately not consulted: it is true for effectively every
 * row (the column default, the factory, and the add path all make it true), so
 * it cannot discriminate, and the sweep does not read it — `payoutBlocker()`
 * checks `bank_code` and `account_number` and nothing else. Requiring it could
 * leave a payable-looking business unfilled for no gain.
 *
 * `is_primary` is **not assumed unique**. No constraint enforces it — the only
 * index on the column is a plain one — and three separate statements in the
 * service maintain it, so concurrent sets can leave two flagged rows. Picking by
 * an ordering rather than by a flag comparison is what makes the answer
 * deterministic anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        $filled = 0;

        // Paged by `id >`, so a row leaving the filtered set as it is filled
        // cannot cause a skip.
        DB::table('businesses')
            ->whereNull('payout_bank_id')
            ->orderBy('id')
            ->select('id')
            ->chunkById(500, function ($businesses) use (&$filled) {
                foreach ($businesses as $business) {
                    $bankId = DB::table('store_banks')
                        ->where('business_id', (int) $business->id)
                        ->orderByDesc('is_primary')
                        ->orderBy('id')
                        ->value('id');

                    if ($bankId === null) {
                        // Named no account at all. Untouched — the wallet banner
                        // is the honest answer until it names one.
                        continue;
                    }

                    // Re-checked in the WHERE and not only in the SELECT above:
                    // this column is a destination, and a business that named one
                    // between the read and the write must keep it.
                    $filled += DB::table('businesses')
                        ->where('id', (int) $business->id)
                        ->whereNull('payout_bank_id')
                        ->update(['payout_bank_id' => (int) $bankId]);
                }
            });

        if ($filled > 0) {
            logger()->info('payout_bank.consolidated_from_setup_step', ['businesses' => $filled]);
        }
    }

    /**
     * Deliberately empty.
     *
     * Reverting the code does not un-name a business's account, and nulling a
     * pointer a business may since have repointed to would un-route a live
     * payout — the opposite of a revert. There is nothing here to reverse.
     */
    public function down(): void
    {
        //
    }
};
