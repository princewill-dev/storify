<?php

namespace App\Jobs;

use App\Exceptions\PayoutNotSentException;
use App\Models\Business;
use App\Models\Payout;
use App\Models\Transaction;
use App\Services\Payments\Custody\InstantPayoutService;
use App\Services\Payments\Custody\PayoutSweepService;
use App\Services\Payments\Custody\PlatformPayoutNotifier;
use App\Services\Payments\Custody\WalletSummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pay this business what it is owed, off the request thread.
 *
 * ## Why this exists
 *
 * `InstantPayoutService` used to be called inline from the three places that collect
 * a sale, so a provider round trip — and a provider that had gone slow — was paid for
 * by the customer's checkout, by the provider's webhook handler and by the POS till.
 * The money work is the same work either way; what this buys is that the request ends
 * when the payment is recorded and the send happens on a worker.
 *
 * It is also where the retry lives, which is the other half of the same instruction:
 * the **scheduled runs are gone** (`custody:promote` and `custody:payouts` are manual
 * tools now), so nothing periodically re-attempts a business that failed. This job
 * does, using the schedule in `custody.retry_backoff_minutes` — and when that is
 * spent it alerts the admins instead of failing quietly, because from that moment the
 * only thing that will move the money is a person.
 *
 * ## It runs the same code the run ran
 *
 * {@see InstantPayoutService::afterCollection()} for a named sale (which owns the
 * guards that make a duplicate dispatch a no-op — `settle_at` and `unwound_at`), or
 * {@see InstantPayoutService::settle()} for a business an operator pressed Retry on.
 * Nothing here decides an amount, a destination or a moment beyond "now".
 *
 * ## Ids, not serialized models
 *
 * The payload is two integers. A model would be tidier to read and worse to rely on:
 * the webhook's own call site dispatches this from inside the transaction that
 * records the payment, so a rollback must leave nothing behind, and with ints a
 * missing row is an explicit early return rather than `ModelNotFoundException`
 * deleting a job out from under the handler.
 *
 * ## Why not `WithQueueConfig`
 *
 * That trait is for mailables and reads `QUEUE_BACKOFF` in **seconds**
 * (`10,30,60`) — a provider that is down would be retried ten seconds later. The
 * payout schedule is `custody.retry_backoff_minutes`, in **minutes**, and it is the
 * same list the send's own refusal-backoff reads, so the two clocks agree by
 * construction. `tries()` and `backoff()` are declared here for that reason.
 *
 * ## What it does not do
 *
 * It does not retry a business that is being *withheld*. A hold, a switched-off
 * `auto_payout`, no bank on file, a destination the provider keeps refusing: each is
 * an answer a person has to act on, each already names itself on the business's own
 * wallet screen, and retrying them on a clock would be three alerts about a decision
 * somebody has already made. Only a refusal, an unreadable provider and a balance
 * left behind are retried.
 */
final class SettlePayouts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  int  $businessId  whose money this is
     * @param  ?int  $transactionId  the sale that triggered it, absent on an operator's retry
     */
    public function __construct(
        public readonly int $businessId,
        public readonly ?int $transactionId = null,
    ) {}

    /**
     * Dispatch this for a sale, if there is a business to settle.
     *
     * Three call sites do this — the provider's webhook, the storefront's return leg
     * and the POS till — and each would otherwise repeat the same null check and the
     * same guard below. `business_id` is nullable on a transaction, and a job asking
     * to pay business `null` is a job that should never exist.
     *
     * ## Why this swallows, when nothing else here does
     *
     * **Enqueueing must not be able to fail the request that collected the payment.**
     * The payment is already committed by the time this is called — every call site
     * dispatches after its own transaction has closed — so an exception raised here
     * cannot undo it; all it can do is turn a recorded, successful payment into an
     * error the caller sees. On the webhook that is a 500, which makes the provider
     * redeliver a payment it already had confirmed.
     *
     * That is not hypothetical, and it is not only the queue being down. **Under the
     * `sync` connection `dispatch()` runs the job inline and rethrows what it throws**
     * — `SyncQueue::handleException()` calls `$queueJob->fail($e)` and then
     * `throw $e` — so this job's own `PayoutNotSentException`, which exists to make
     * the worker retry, would come straight back out of here on any queue that is not
     * a real one. `phpunit.xml` sets `QUEUE_CONNECTION=sync`, so that is every test,
     * and a refused payout would have failed a webhook the suite asserts is a 200.
     *
     * So the throw stays where it belongs — in `handle()`, where the worker's
     * `backoff()` and `tries()` read it — and this door refuses to pass it on. What is
     * lost is a genuine enqueue failure on the real `database` queue, which is logged
     * rather than raised; it is nearly unreachable there, because the `jobs` row goes
     * onto the same connection the payment just committed on, and the net for it is
     * the one the webhook already takes — see `PaymentWebhookProcessor::settle()`'s
     * redelivery branch.
     */
    public static function forSale(Transaction $transaction): void
    {
        if ($transaction->business_id === null) {
            return;
        }

        self::push((int) $transaction->business_id, (int) $transaction->id);
    }

    /**
     * Settle a business with no sale behind it.
     *
     * The path for money that became payable for a reason other than a customer
     * paying: a hold lifted, a ceiling cleared, a business flipped into custody. Each
     * of those used to be noticed by the five-minute run, and none of them dispatches
     * anything by itself now — so whoever performs the act says so here.
     *
     * No `transactionId`, which is not the same as "no sale": it means the job goes
     * straight to {@see InstantPayoutService::settle()} instead of entering through
     * `afterCollection()`'s per-sale guards. That is the point — there is no sale to
     * check `settle_at` or `unwound_at` on, and the business is owed what its wallet
     * says either way.
     */
    public static function forBusiness(int $businessId): void
    {
        self::push($businessId, null);
    }

    /**
     * Queue it, and refuse to pass on what queueing throws.
     *
     * See {@see self::forSale()} for why this swallows when the job itself does not:
     * every caller here is somewhere a payment or an operator's decision has *already*
     * been committed, and a queue that cannot take the row must not turn that into an
     * error. Under the `sync` connection, which is every test, this is also what keeps
     * the job's own `PayoutNotSentException` from coming back out of the caller.
     */
    private static function push(int $businessId, ?int $transactionId): void
    {
        try {
            self::dispatch($businessId, $transactionId);
        } catch (Throwable $e) {
            Log::error('custody.payout.not_queued', [
                'business_id' => $businessId,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * How many times the platform asks the provider before a person is told.
     *
     * The same ceiling the send reads (`custody.retry_attempts`), deliberately: the
     * queue's budget and the `retries_exhausted` blocker are the same number, so the
     * job giving up and the business being marked blocked happen together rather than
     * one trailing the other.
     */
    public function tries(): int
    {
        return max(1, (int) config('custody.retry_attempts', 3));
    }

    /**
     * The gap before each attempt, in seconds, from the list held in minutes.
     *
     * The worker indexes this by attempt number and repeats the last value beyond the
     * end (`Worker::calculateBackoff()`), so a shorter list is a schedule for the first
     * few attempts rather than a bug.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        $minutes = config('custody.retry_backoff_minutes', [5, 30, 60]);

        $minutes = is_array($minutes) && $minutes !== [] ? $minutes : [5, 30, 60];

        return array_values(array_map(static fn ($gap): int => max(1, (int) $gap) * 60, $minutes));
    }

    public function handle(InstantPayoutService $instant, WalletSummaryService $wallet): void
    {
        $business = Business::query()->find($this->businessId);

        // The business is gone. There is nobody to pay and nobody to tell.
        if (! $business instanceof Business) {
            return;
        }

        $result = $this->settle($instant, $business);

        // Everything that is not `attempted` is an answer rather than a stall — the
        // sale was never credited, or has been taken back, or a person has to act.
        // The clock changes none of them, and `failed()` would be an alert about a
        // decision somebody already made.
        if (($result['status'] ?? null) !== 'attempted') {
            return;
        }

        // Where the money is now. The tally cannot say: `sweepBusiness()` reports what
        // it *sent* and never what it could not read, so an unreachable provider and a
        // business that owes nothing both come back as an empty array. The wallet —
        // the same read model the business's own screen uses — knows the difference,
        // and going through it rather than the business's columns is also what keeps
        // `custody.per_store_wallets` from needing a case here.
        $summary = $wallet->forBusiness($business);

        $refused = ($result['outcome']['failed'] ?? 0) > 0
            || ($result['outcome']['backoff'] ?? 0) > 0;

        // `payable_kobo`, deliberately, and **not** `available_kobo`. The two are
        // equal in the ordinary case and differ in exactly the one that matters
        // here: a **store hold** withholds that store's share and pays the business
        // for the rest, so it leaves `available_balance` untouched while taking the
        // money out of the walk's figure. Retrying on `available_kobo` would
        // therefore spend three attempts and send an admin alert about a hold
        // somebody placed on purpose — a page with nothing wrong on it, which is the
        // thing `PayoutSweepService::STALLING_BLOCKERS` exists to avoid.
        //
        // What `payable_kobo > 0` means after a send is that money the walk says
        // should go did not — a refusal, or the provider's balance short of it.
        // `pending_kobo > 0` means a due sale did not mature, which for both rails
        // (`settlement_hours` 0) has only two causes and both are worth retrying:
        // the provider could not be read, so the gate fell back to the 72-hour floor
        // and a fresh sale is still inside it; or the gate was read and short.
        $leftBehind = (int) ($summary['pending_kobo'] ?? 0) > 0
            || (int) ($summary['payable_kobo'] ?? 0) > 0;

        // Sent, or nothing was ever owed. Either way this job is finished.
        if (! $refused && ! $leftBehind) {
            return;
        }

        $attempt = $this->attempts();

        Log::warning('custody.payout.retry', [
            'business_id' => $business->id,
            'transaction_id' => $this->transactionId,
            'attempt' => $attempt,
            'of' => $this->tries(),
            'refused' => $refused,
            'pending_kobo' => (int) ($summary['pending_kobo'] ?? 0),
            'payable_kobo' => (int) ($summary['payable_kobo'] ?? 0),
            'withheld_kobo' => (int) ($summary['withheld_kobo'] ?? 0),
            'measured' => $result['measured'] ?? null,
            'outcome' => $result['outcome'] ?? null,
        ]);

        throw new PayoutNotSentException(
            $business,
            $refused ? 'the provider refused the transfer' : 'the money never left the wallet',
            $attempt,
            $this->tries(),
        );
    }

    /**
     * The attempt budget is spent. Tell the platform, then let the job die.
     *
     * Reached through the worker's own failure path, which calls this on the job
     * instance **and not through the container**
     * (`CallQueuedHandler::failed()` calls `$command->failed($e)` directly), so
     * everything it needs is resolved here rather than asked for in the signature.
     *
     * The job also lands in `failed_jobs`, which is the second, quieter net: it is a
     * record an operator can read and `queue:retry`.
     */
    public function failed(Throwable $e): void
    {
        $business = Business::query()->find($this->businessId);

        Log::channel('queue')->error('custody.payout.gave_up', [
            'business_id' => $this->businessId,
            'transaction_id' => $this->transactionId,
            'attempts' => $this->tries(),
            'error' => $e->getMessage(),
            'business_found' => $business instanceof Business,
        ]);

        if (! $business instanceof Business) {
            return;
        }

        $sweep = app(PayoutSweepService::class);

        // A ceiling in force if one is, and a reason of its own if not — because the
        // commonest way to arrive here is a provider that never answered, which leaves
        // no ceiling and often no payout row either, the money having never been
        // reserved. That case is exactly the one an operator most needs to hear about.
        $blocker = $sweep->payoutBlocker($business) ?? [
            'code' => PlatformPayoutNotifier::SETTLEMENT_FAILED,
            'label' => sprintf(
                'Nothing was sent and the provider never took the money — %d attempts spent',
                $this->tries(),
            ),
        ];

        app(PlatformPayoutNotifier::class)->payoutsStalled(
            $business,
            $this->stalledPayout($business),
            $blocker,
            $this->tries(),
        );
    }

    /**
     * The transfer the alert should quote, or null when nothing is outstanding.
     *
     * In flight first, newest overall second, and the order is the whole point. The
     * newest row alone is wrong in the commonest case this job ends in: a business
     * the platform has been paying for months has a long history, and the row just
     * written by an in-flight attempt is *not* the newest if a later attempt was
     * refused — `fail()` writes it back to `failed` — so "latest" would quote a
     * transfer from a previous outage while the money that is actually stuck sits
     * older and unnamed.
     *
     * The fallback is not dead code either: a provider that keeps refusing leaves
     * every row `failed`, and those are exactly the rows an operator wants to read.
     * Null is reserved for the genuine nothing-was-ever-sent case — an unreadable
     * provider reserves nothing — which the notifier and the mail both handle.
     */
    private function stalledPayout(Business $business): ?Payout
    {
        return Payout::query()
            ->where('business_id', $business->id)
            ->whereIn('status', [Payout::STATUS_PENDING, Payout::STATUS_PROCESSING])
            ->latest('id')
            ->first()
            ?? Payout::query()->where('business_id', $business->id)->latest('id')->first();
    }

    /**
     * Settle the business, entering through whichever guard chain applies.
     *
     * A named sale goes through {@see InstantPayoutService::afterCollection()} so the
     * checks that make a duplicate dispatch harmless are the ones the request path
     * already used; a bare business goes straight to
     * {@see InstantPayoutService::settle()} because there is no sale to check.
     *
     * @return array{status: string, reason?: string, matured?: int, hold_id?: int|null, measured?: bool, outcome?: array<string, int>}
     */
    private function settle(InstantPayoutService $instant, Business $business): array
    {
        if ($this->transactionId === null) {
            return $instant->settle($business);
        }

        $transaction = Transaction::query()->find($this->transactionId);

        // The sale this job was dispatched for is gone: its transaction rolled back,
        // or the row was removed. There is nothing this was ever going to settle, and
        // a business is not owed anything *because of a sale that no longer exists*.
        if (! $transaction instanceof Transaction) {
            return ['status' => 'nothing_credited'];
        }

        return $instant->afterCollection($transaction);
    }
}
