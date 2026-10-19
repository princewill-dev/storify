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
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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
 *
 * ## Two stalls, two clocks
 *
 * **A provider that is broken and a provider that has not paid yet are not the same
 * event**, and treating them as one was a real fault rather than a rough edge. A
 * refusal or an unreadable balance is an incident: three attempts on
 * `custody.retry_backoff_minutes`, then `failed()` and an admin alert.
 *
 * A provider that answered and is simply *not holding the money yet* — a card charge
 * still settling on the provider's own schedule — produces the identical record in
 * the log and needs the opposite response. It is not an incident, and at five minutes
 * it is not even informative: the retry budget would be spent inside the first hour
 * and an operator would be alerted about a payment that was never sendable and that
 * fixes itself. That path is quiet instead, and re-checks on
 * `custody.settlement_lag_backoff_minutes` — see {@see self::recheckLater()}. Only
 * when that chain runs out is anybody told.
 *
 * The two clocks are separate lists because the thing being waited for has a
 * different order of magnitude: minutes for a provider to come back, hours for money
 * to settle.
 *
 * **And two more states wearing the same disguise.** A sale also stays in
 * `pending_balance` when the provider cannot fund a payout at all — because the
 * wallets claim more than the provider actually holds, or because what it holds is
 * already fully committed to other payouts and the fee. The record is identical to a
 * settlement lag; the correct response is the opposite. Neither clears with time,
 * every sale behind them is gated off, and waiting only delays the reconciliation.
 * Those are *not* quiet — the quiet path is conditioned on
 * {@see PayoutSweepService::gateCanFundPayout()}, so they take the fast clock and
 * reach `failed()` and an operator.
 */
final class SettlePayouts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  int  $businessId  whose money this is
     * @param  ?int  $transactionId  the sale that triggered it, absent on an operator's retry
     * @param  int  $lagAttempt  how many settlement re-checks this business has already had
     */
    public function __construct(
        public readonly int $businessId,
        public readonly ?int $transactionId = null,
        int $lagAttempt = 0,
    ) {
        $this->lagAttempt = max(0, $lagAttempt);
    }

    /**
     * How many times this business has already been re-checked for money the
     * provider has not released yet.
     *
     * **A declared property with a default, deliberately not a promoted one.** A job
     * already sitting in the `jobs` table when this field was added deserializes
     * without it, and a promoted typed property would then be uninitialised and throw
     * on the first read — turning a deploy into a failed payout. Declared this way,
     * such a job comes back as `0`, which is exactly what it is: the first check.
     */
    public int $lagAttempt = 0;

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
    private static function push(int $businessId, ?int $transactionId, int $lagAttempt = 0): void
    {
        try {
            self::dispatch($businessId, $transactionId, $lagAttempt);
        } catch (Throwable $e) {
            Log::error('custody.payout.not_queued', [
                'business_id' => $businessId,
                'transaction_id' => $transactionId,
                'lag_attempt' => $lagAttempt,
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

    public function handle(
        InstantPayoutService $instant,
        WalletSummaryService $wallet,
        PayoutSweepService $sweep,
    ): void {
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

        // `sendable_kobo`, deliberately, and **not** `available_kobo`. It is what
        // this attempt was willing to send: everything owed, less money a **store
        // hold** withholds, less a destination waiting for the platform's minimum
        // payout amount or whose whole balance the payout fee would swallow.
        //
        // `payable_kobo` cannot be used for this any more. It means "owed", and a
        // business deliberately waiting under the floor would then look identical
        // to one whose send failed: three retries inside the hour and an admin
        // alert about money nobody was trying to move. Retrying on
        // `available_kobo` would fail the same way for a store hold, which takes
        // the money out of the walk while leaving the wallet's balance untouched
        // — a page with nothing wrong on it, which is the thing
        // `PayoutSweepService::STALLING_BLOCKERS` exists to avoid.
        //
        // What `sendable_kobo > 0` means after a send is that money the walk says
        // should go did not — a refusal, or the provider's balance short of it.
        // `pending_kobo > 0` means a due sale did not mature, which for both rails
        // (`settlement_hours` 0) has only two causes: the provider could not be read,
        // so the gate fell back to the 72-hour floor and a fresh sale is still inside
        // it; or the gate was read and short. The first is trouble and the second is
        // usually not, which is why the two are separated below rather than both
        // being sent round the fast clock.
        $sendable = (int) ($summary['sendable_kobo'] ?? 0);

        $leftBehind = (int) ($summary['pending_kobo'] ?? 0) > 0 || $sendable > 0;

        // Sent, or nothing was ever owed. Either way this job is finished.
        if (! $refused && ! $leftBehind) {
            return;
        }

        $gate = is_array($result['gate'] ?? null) ? $result['gate'] : [];

        // ---------------------------------------------------------------
        // First: the case that is not an incident
        // ---------------------------------------------------------------
        //
        // The provider was read, it refused nothing, nothing was payable, and a sale
        // is sitting in `pending_balance`. That is a charge the provider has recorded
        // but not yet released — it settles on its own schedule and the money becomes
        // sendable with no action from anybody.
        //
        // That is not a reason to spend three attempts inside the hour and email an
        // operator. The whole point of the retry budget is that reaching its end means
        // something needs a person; spending it on a payment that was never sendable
        // makes the alert routine, and a routine alert is one nobody reads on the day
        // it means something.
        //
        // ---------------------------------------------------------------
        // …and the states that look exactly like it and are not
        // ---------------------------------------------------------------
        //
        // A sale can also sit in `pending_balance` while the provider cannot fund
        // anything at all — because the platform's wallets claim more than the
        // provider's balance contains, or because what it holds is already fully
        // committed. **Those are the shapes this must not wait out.** Nothing about
        // either improves with time, every sale behind them is gated off, and left on
        // the quiet clock this would have reported nothing for seventeen hours and
        // then complained in the wrong terms — having told nobody while an operator
        // still had a chance to reconcile it.
        //
        // So "the provider could actually send something" is a condition of the quiet
        // path, not an afterthought. It is read by the sweep, from the same four
        // figures the reason is composed from, so the decision to stay quiet and the
        // sentence explaining the eventual alert can never disagree about which state
        // this was.
        //
        // The five conditions are deliberately narrow. `refused` covers a refusal and
        // a transfer still inside its own backoff window; `measured !== true` covers a
        // provider that could not be read, which is the provider being *down* and is
        // exactly what the fast clock is for; `$fundable` excludes a provider that
        // cannot pay anybody; and `sendable_kobo === 0` excludes the case where the
        // walk was willing to send and sent nothing, which is trouble whatever the
        // reason — while a policy hold is deliberately not "willing to send".
        $waitingOnProvider = ! $refused
            && ($result['measured'] ?? false) === true
            && $sweep->gateCanFundPayout($gate)
            && $sendable === 0
            && (int) ($summary['pending_kobo'] ?? 0) > 0;

        if ($waitingOnProvider && $this->recheckLater($gate)) {
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
            // Owed versus sendable: the difference is a policy hold, and a hold
            // is never what this line is warning about.
            'sendable_kobo' => $sendable,
            'withheld_kobo' => (int) ($summary['withheld_kobo'] ?? 0),
            'measured' => $result['measured'] ?? null,

            // The subtraction behind the promotion budget, and the reason this
            // line is worth reading at all. Every stall this job retries comes
            // back as the same shape — nothing sent, nothing matured — while the
            // causes behind it need opposite responses: a provider that has not
            // released a customer's money yet will settle itself if it is given
            // time, whereas a platform balance that is fully spoken for will not
            // move at all until something else clears. Without these figures the
            // line says only that the job tried again, which is the one thing
            // already known.
            'budget_kobo' => $gate['budget'] ?? null,
            'gate' => $gate['components'] ?? null,

            // Whether the provider could have funded a payout at all, which is the
            // single figure that separates the two stalls this line has to
            // distinguish. False means the balance is short or fully committed and
            // the money will not move on its own, however many times this comes
            // back; true means it is only a matter of what has been released yet.
            'provider_can_fund' => $sweep->gateCanFundPayout($gate),
            'shortfall_kobo' => $sweep->gateShortfallKobo($gate),

            'outcome' => $result['outcome'] ?? null,
        ]);

        throw new PayoutNotSentException(
            $business,
            $sweep->stallReason($refused, $summary, $gate),
            $attempt,
            $this->tries(),
        );
    }

    /**
     * Queue the next look at money the provider has not released, and say whether it
     * went.
     *
     * **A re-check is a new job, and it has to be.** The worker's `backoff()` and
     * `tries()` are not read from this object at the moment of failure — they are
     * rendered into the queue payload when the job is *pushed*
     * (`Queue::getJobBackoff()`) and read back out of that payload later
     * (`Illuminate\Queue\Jobs\Job::backoff()`), so they cannot be made to depend on
     * anything `handle()` discovers. There is no way to tell the worker "this
     * particular stall deserves an hour, not five minutes" from inside `handle()`.
     * Waiting an hour therefore means pushing a job that will not run until then.
     *
     * **Deliberately not {@see self::push()}**, which swallows. Swallowing is right for
     * the three call sites that queue this for a sale — they are somewhere a payment
     * has already been committed and must not be turned into an error. Here it would
     * be dangerous in the opposite direction: if the re-check cannot be queued and the
     * failure is hidden, the chain silently ends with the money owed and *nobody*
     * told, which is the one outcome the fast path's alert exists to prevent. So this
     * reports rather than swallows, and the caller falls through to the throw — a
     * re-check that cannot be queued behaves exactly as this job did before re-checks
     * existed.
     *
     * Returns false when the chain is spent, which is not a failure: the business has
     * had every check this platform is willing to make quietly, and the caller's throw
     * is what turns that into a person being told.
     *
     * @param  array<string, mixed>  $gate  the terms the quiet decision was made on
     */
    private function recheckLater(array $gate): bool
    {
        // **A queue that cannot defer cannot re-check.** On the `sync` driver
        // `later()` ignores its delay and runs the job inline, so a re-check would
        // not be a later look at anything — it would recurse down the schedule inside
        // the current call, asking the provider four times in a row and failing the
        // nested jobs on the way, each of which alerts. Standing the whole mechanism
        // down here is what keeps a sync environment on the behaviour that existed
        // before it, which is the fast path falling through to the alert.
        //
        // `Queue::fake()` is not caught by this: `QueueFake::connection()` returns the
        // fake itself rather than the underlying driver, so the tests that exercise
        // the quiet path exercise it for real.
        if (Queue::connection() instanceof SyncQueue) {
            return false;
        }

        $schedule = config('custody.settlement_lag_backoff_minutes', [60, 240, 720]);

        $schedule = is_array($schedule) && $schedule !== []
            ? array_values($schedule)
            : [60, 240, 720];

        if ($this->lagAttempt >= count($schedule)) {
            return false;
        }

        $minutes = max(1, (int) $schedule[$this->lagAttempt]);

        Log::info('custody.payout.settling', [
            'business_id' => $this->businessId,
            'transaction_id' => $this->transactionId,
            // Which check this was, and when the next one is — the two facts that
            // say whether this is a charge that settled late or one that never
            // arrived.
            'check' => $this->lagAttempt + 1,
            'of' => count($schedule),
            'next_check_in_minutes' => $minutes,

            // The figures that decided this was the quiet path rather than an
            // alert. A quiet line that says only "waiting" is unfalsifiable: it
            // reads the same for money the provider is about to release and for a
            // platform that is short and will never release it. The terms are what
            // let somebody reading back through the log tell which of the two this
            // actually was, hours later, without reproducing the arithmetic against
            // provider state that has since moved.
            'budget_kobo' => $gate['budget'] ?? null,
            'gate' => $gate['components'] ?? null,
        ]);

        try {
            // `delay()`, so the row is written now and becomes claimable later. That
            // is the difference between this and a `sleep()`: no worker is held, and
            // the money is not waiting on a process staying alive.
            self::dispatch($this->businessId, $this->transactionId, $this->lagAttempt + 1)
                ->delay(now()->addMinutes($minutes));

            return true;
        } catch (Throwable $e) {
            // Reached when the `jobs` row could not be written — a queue that is
            // down, or a payload the connection would not accept. Rare, and it must
            // not be silent: the caller's throw is what reaches the operator.
            Log::channel('queue')->warning('custody.payout.recheck_not_queued', [
                'business_id' => $this->businessId,
                'lag_attempt' => $this->lagAttempt,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
            // How many quiet looks this business had before the loud one, and so how
            // long it has actually been stuck. Zero for the ordinary provider
            // refusal; the whole settlement schedule for a charge that never
            // settled. Without it the line reads the same whether this failed in the
            // last half hour or has been waiting since yesterday.
            'settlement_rechecks' => $this->lagAttempt,
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
        //
        // The exception's own reason is preferred to a fixed string, because it is
        // the only text in the alert that says *which* stall this was. "Nothing was
        // sent" is true of every way of arriving here and so is worth little to the
        // operator deciding whether to press Retry now or to wait.
        $reason = $e instanceof PayoutNotSentException ? $e->reason : null;

        // How long this has been going on, said in the label rather than only in the
        // log, because an operator deciding whether to press Retry now or to wait
        // needs it and is not going to go and read a log first. A business that came
        // down the settlement chain has been waiting hours across checks that
        // deliberately said nothing; one that was refused has been waiting minutes.
        // The word for both is "attempts", which is why the quiet ones are named
        // separately rather than counted in with the loud ones.
        $quiet = $this->lagAttempt > 0
            ? sprintf(' and %d settlement re-checks over the preceding hours', $this->lagAttempt)
            : '';

        $blocker = $sweep->payoutBlocker($business) ?? [
            'code' => PlatformPayoutNotifier::SETTLEMENT_FAILED,
            'label' => $reason !== null && $reason !== ''
                ? sprintf('Nothing was sent in %d attempts%s — %s', $this->tries(), $quiet, $reason)
                : sprintf(
                    'Nothing was sent and the provider never took the money — %d attempts spent%s',
                    $this->tries(),
                    $quiet,
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
