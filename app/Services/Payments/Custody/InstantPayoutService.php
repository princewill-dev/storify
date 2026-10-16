<?php

namespace App\Services\Payments\Custody;

use App\Jobs\SettlePayouts;
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
 * **nothing left to wait for**, and what used to stand between a customer's payment
 * and the business's bank account was the gap between two five-minute cron ticks:
 * our latency and nobody else's.
 *
 * ## Nothing waits on this
 *
 * `afterCollection()` is reached through {@see SettlePayouts}, which runs
 * on the queue, so the request that collects a sale ends without waiting on a
 * provider round trip. That job is also the retry — it gives a provider that is down
 * the schedule in `custody.retry_backoff_minutes` and then alerts the admins, rather
 * than paying late and silently. **There is no scheduled run any more.** The
 * five-minute `custody:promote` / `custody:payouts` pair is gone from the scheduler,
 * and both commands survive only as manual tools for an incident.
 *
 * ## It is not a second way to move money
 *
 * Every step here is the run's own code, narrowed:
 *
 *   restrictions  -> {@see PayoutSweepService::restrictionFor()}
 *   pending->available -> {@see CustodyService::promoteForBusiness()}
 *   available->provider -> {@see PayoutSweepService::sweepBusiness()}
 *
 * Those three **are** {@see self::settle()}, the whole of "settle this business
 * now", and it is the only thing that moves money after a sale — reached two ways,
 * the queued job and the operator's retry in {@see PayoutRetryService}, so neither
 * is a second path. `PayoutRetryService` goes through here rather than calling
 * `sweepBusiness()` itself for a reason worth keeping: `sweepBusiness()` sends what
 * is already in `available_balance` and never consults the gate, so money a provider
 * outage left behind in `pending_balance` would be invisible to it and the operator's
 * button would report success while the business stayed unpaid.
 *
 * Nothing here writes a balance or a payout row that the run would not have written;
 * it only gets there sooner. The claims are what make that safe: `promoted_at` and
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
 * It never throws. A provider that cannot be reached, a balance that cannot be read
 * or a wallet that refuses the debit all come back as a status, and the caller
 * decides what that means — the job retries and alerts; the operator's retry
 * reports. Throwing would make a provider's bad afternoon the payment request's
 * problem, and the settlement itself is already committed by the time this is
 * called: the sale stays payable until something pays it. The job is what turns
 * "still payable" into "tried again in five minutes", and then into an email to the
 * admins.
 *
 * `custody.instant_payout.enabled` turns it off without a deploy, and the money then
 * waits for an operator's retry.
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
     * The guards are this method's own and settle the business no further: a sale
     * that is not the platform's, or not yet credited, or already taken back, is
     * answered here and never reaches the wallet.
     *
     * Safe to call more than once for one sale, and safe to call for a sale
     * somebody else already settled: `settle_at` is the credit's own proof, so the
     * second caller finds the wallet already credited and lets
     * {@see PayoutSweepService::sweepBusiness()} answer with nothing to send. That
     * is what makes {@see SettlePayouts} safe to hand a sale that has
     * already been paid, which is what a provider redelivering its webhook does.
     *
     * @return array{status: string, reason?: string, matured?: int, hold_id?: int|null, measured?: bool, gate?: array, outcome?: array<string, int>}
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

        $result = $this->settle($business);

        if ($result['status'] === 'withheld') {
            Log::info('custody.payout.instant_withheld', [
                'transaction_id' => $transaction->id,
                'business_id' => $business->id,
                'reason' => $result['reason'],
                'hold_id' => $result['hold_id'],
                'matured' => $result['matured'],
            ]);
        } else {
            // `gate` is the terms the promotion budget was computed from, and it is
            // logged on the *success* path too — not only when something goes wrong.
            // A sale that matured nothing and sent nothing looks identical here
            // either way, and this line is the first thing anyone reads when a
            // business asks why its money has not moved. `outcome` says what the
            // send did; only these four figures say whether there was anything for
            // it to do.
            Log::info('custody.payout.instant', [
                'transaction_id' => $transaction->id,
                'business_id' => $business->id,
                'provider' => $transaction->settlementProviderCode(),
                'matured' => $result['matured'],
                'budget_measured' => $result['measured'],
                'budget_kobo' => $result['gate']['budget'] ?? null,
                'gate' => $result['gate']['components'] ?? null,
                'outcome' => $result['outcome'],
            ]);
        }

        return $result;
    }

    /**
     * Settle this business: mature what may mature, then send what is owed.
     *
     * The one place money moves after a sale, whichever asked — the queued job that
     * runs when a sale is collected, or an operator's retry. Extracted so both go
     * through it rather than one of them reaching for `sweepBusiness()` alone, which
     * would send only what is already in `available_balance` and leave a provider
     * outage's leftovers in `pending_balance` looking like a business that was
     * already paid.
     *
     * Reads no transaction and writes no log: what to make of the answer is the
     * caller's, and the two callers make different things of it.
     *
     * @return array{status: string, reason?: string, matured?: int, hold_id?: int|null, measured?: bool, gate?: array, outcome?: array<string, int>}
     */
    public function settle(Business $business): array
    {
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
        // one is holding is the one from before the credit — and the send reads
        // it to decide whether there is anything to send. Without this it finds
        // an empty wallet and sends nothing, silently, which is the one failure
        // here nobody would notice: no error, no payout, and money that looks
        // paid because the sale matured.
        $business->refresh();

        $restriction = $this->payouts->restrictionFor($business);

        if ($restriction !== null) {
            return [
                'status' => 'withheld',
                'reason' => $restriction['code'],
                'matured' => $matured,
                'hold_id' => $restriction['hold']?->id,
            ];
        }

        // Run even when this sale matured nothing: a business may already hold
        // settled money from an earlier sale that nothing has reached, and this is
        // the moment somebody is watching. It costs nothing when there is nothing
        // payable — the walk finds no balance and no provider call is made.
        $outcome = $this->payouts->sweepBusiness($business);

        // `measured` travels with the answer because an unreadable provider and a
        // business that owes nothing both come back as an empty tally — the send
        // reports what it sent and never what it could not read — so the caller
        // that has to decide whether to try again cannot learn it from `outcome`.
        //
        // The gate travels with it for the same reason, one level deeper. A sale
        // that matured nothing comes back here as `matured: 0` with an empty
        // outcome whether the provider was holding nothing, another business's
        // stranded balance was sitting on the budget, or transfers already in
        // flight had spent it. That distinction is the whole difference between
        // "the provider has not released the customer's money yet" and "the
        // platform's own balance is short", and only the terms of the subtraction
        // can draw it — so they come out with the answer rather than being
        // recomputed, differently and approximately, by whichever caller is
        // deciding whether to try again.
        return [
            'status' => 'attempted',
            'matured' => $matured,
            'measured' => $gate['measured'],
            'gate' => $gate,
            'outcome' => $outcome,
        ];
    }
}
