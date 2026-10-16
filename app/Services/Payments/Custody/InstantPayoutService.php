<?php

namespace App\Services\Payments\Custody;

use App\Models\Business;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

/**
 * Pays a business out at the moment its money is collected, instead of at the
 * next tick of the payout run.
 *
 * ## Why this exists
 *
 * Under collect-and-pay-out the money reaches the platform's provider balance the
 * moment a charge succeeds — Bachs reports the charge in `available_balance` with
 * nothing in `pending_balance` and no `pending_settlements_by_day` — and the
 * settlement window is zero for that provider precisely because the provider's own
 * balance is a better answer than any clock. So after a sale is recorded there is
 * **nothing left to wait for**. The only thing that used to stand between a
 * customer's payment and the business's bank account was the gap between two
 * five-minute cron ticks, which is our latency and nobody else's.
 *
 * ## It is not a second way to move money
 *
 * Every step here is the run's own code, narrowed:
 *
 *   restrictions  -> {@see PayoutSweepService::restrictionFor()}
 *   pending->available -> {@see CustodyService::promoteForBusiness()}
 *   available->provider -> {@see PayoutSweepService::sweepBusiness()}
 *
 * The cron calls the same three, over every candidate. Nothing here writes a
 * balance or a payout row that the run would not have written; it only gets there
 * sooner. The claims are unchanged and are what make that safe: `promoted_at` and
 * `settle_at` taken by condition, and a reservation that debits the wallet under a
 * row lock and refuses to overdraw — so a trigger and a run landing on the same
 * business at the same instant cannot pay it twice. The loser finds the claim gone
 * and stops, or is refused a reservation it cannot cover and is counted `skipped`.
 *
 * ## The restrictions decide the send, and nothing else
 *
 * They are read before the provider is asked for money, so a business a hold or
 * a dead bank is stopping costs no transfer. They are read **after** the sale has
 * matured, deliberately: a hold stops money leaving, it does not change what the
 * wallet records as owed, and the promote run reads no hold either. In
 * {@see PayoutSweepService::payoutBlocker()}'s order, which is the order the
 * business's own wallet screen and the run's report already use:
 *
 * 1. A **system** hold — the whole platform's payouts stopped.
 * 2. A **business** hold, or `auto_payout` off, which is the business's own switch.
 * 3. No payout bank on file, or a destination the provider keeps refusing.
 *
 * A **store** hold is not one of them: it does not block this call but withholds
 * that store's share and pays the business for the rest, inside
 * {@see PayoutSweepService::payableFor()}. A balance made up entirely of a held
 * store's sales comes back with nothing to send and is not paid.
 *
 * ## What it does not do
 *
 * It never fails a request. A provider that cannot be reached, a balance that
 * cannot be read or a wallet that refuses the debit are all logged and left to the
 * run, which is the safety net for exactly this: the settlement itself is already
 * committed by the time this is called, and the sale stays payable until something
 * pays it. Turning a slow provider into a failed webhook would have the provider
 * redeliver a payment we have already recorded, to pay a business we can still pay
 * on the next pass.
 *
 * `custody.instant_payout.enabled` turns it off without a deploy, and the run then
 * pays on its own schedule exactly as it did before this existed.
 */
final class InstantPayoutService
{
    public function __construct(
        private readonly CustodyService $wallet,
        private readonly PayoutSweepService $payouts,
    ) {}

    /**
     * Offer to pay this business for the sale that has just been collected.
     *
     * Safe to call more than once for one sale, and safe to call for a sale
     * somebody else already settled: `settle_at` is the credit's own proof, so the
     * second caller finds the wallet already credited and lets
     * {@see PayoutSweepService::sweepBusiness()} answer with nothing to send.
     *
     * @return array{status: string, reason?: string, matured?: int, outcome?: string|null}
     */
    public function afterCollection(Transaction $transaction): array
    {
        if (! (bool) config('custody.instant_payout.enabled', true)) {
            return ['status' => 'disabled'];
        }

        $business = $transaction->business;

        if (! $business instanceof Business) {
            return ['status' => 'no_business'];
        }

        // Only a sale the platform collected, and only once the wallet has been
        // credited for it. `settle_at` is stamped by
        // {@see CustodyService::recordCollectedSale()} and by nothing else, so a
        // sale that could not be credited — one whose figure is genuinely
        // unknowable yet — answers here and goes no further.
        if (! $transaction->isPlatformManaged() || $transaction->settle_at === null) {
            return ['status' => 'nothing_credited'];
        }

        // Credited and then taken back: a refund or a chargeback. There is no
        // money owed, and `unwound_at` is what the promote run excludes on too.
        if ($transaction->unwound_at !== null) {
            return ['status' => 'unwound'];
        }

        // Matured first, and **regardless of anything below**. A hold stops money
        // being *sent*; it does not change what the wallet records as owed. The
        // promote run reads no hold either, so a business whose sales stayed in
        // `pending_balance` merely because it was held would show one balance on
        // a sale the run had touched and another on a sale this had, for no
        // reason a business could see.
        //
        // One reading of the provider's balance, both halves used together: the
        // budget decides whether the sale may mature, and the floor is what an
        // unreadable balance falls back to, so a provider we cannot ask degrades
        // to the conservative path rather than to an unverified instant payout.
        $gate = $this->payouts->promotionGate();

        $matured = $this->wallet->promoteForBusiness($business, $gate['budget'], $gate['floor']);

        // **The wallet was written behind this instance's back.** `promoteForBusiness()`
        // credits through each sale's own business instance, so the number this
        // one is holding is the one from before the credit — and the sweep reads
        // it to decide whether there is anything to send. Without this it finds
        // an empty wallet and sends nothing, silently, which is the one failure
        // here nobody would notice: no error, no payout, and money that looks
        // paid because the sale matured.
        $business->refresh();

        $restriction = $this->payouts->restrictionFor($business);

        if ($restriction !== null) {
            Log::info('custody.payout.instant_withheld', [
                'transaction_id' => $transaction->id,
                'business_id' => $business->id,
                'reason' => $restriction['code'],
                'hold_id' => $restriction['hold']?->id,
                'matured' => $matured,
            ]);

            return ['status' => 'withheld', 'reason' => $restriction['code'], 'matured' => $matured];
        }

        // Run even when this sale matured nothing: a business may already hold
        // settled money from an earlier sale that the run has not reached, and
        // this is the moment somebody is watching. It costs nothing when there is
        // nothing payable — the walk finds no balance and no provider call is
        // made.
        $outcome = $this->payouts->sweepBusiness($business);

        Log::info('custody.payout.instant', [
            'transaction_id' => $transaction->id,
            'business_id' => $business->id,
            'provider' => $transaction->settlementProviderCode(),
            'matured' => $matured,
            'budget_measured' => $gate['measured'],
            'outcome' => $outcome,
        ]);

        return ['status' => 'attempted', 'matured' => $matured, 'outcome' => $outcome];
    }
}
