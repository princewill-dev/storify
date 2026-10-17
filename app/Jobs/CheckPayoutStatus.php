<?php

namespace App\Jobs;

use App\Models\Business;
use App\Models\Payout;
use App\Services\Payments\Custody\PayoutSweepService;
use App\Services\Payments\Custody\PlatformPayoutNotifier;
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
 * Ask the provider what became of a transfer, starting five seconds after we sent it.
 *
 * ## Why this exists
 *
 * `PayoutSweepService::apply()` leaves a row `processing` whenever the provider
 * accepted a transfer without completing it, and from that moment the platform's only
 * way to learn what happened was the provider's webhook. Webhooks get dropped. Ours
 * do not always arrive, and before this job the result was a row sitting in
 * `processing` with the money out of the wallet, the business not paid, and nobody
 * looking.
 *
 * `PayoutSweepService::reconcile()` could have covered it, and used to — but the
 * scheduled runs are gone (`routes/console.php` has the reasoning), so `custody:payouts`
 * is a tool an operator reaches for during an incident rather than a clock. **This job
 * is therefore what resolves a payout whose webhook never came**, and the alert at the
 * end of its ladder is what tells a person when it could not.
 *
 * ## The ladder, and how to read it without getting it wrong
 *
 * `custody.payout_status_backoff_seconds` is a list of waits in **seconds**, one entry
 * per question. **Entry 1 is the wait before the first question** and entry *n* the gap
 * before question *n*, so the number of questions a payout gets is `count()` and the
 * first one lands five seconds after the send with the shipped default.
 *
 * A job carries **the number of the check it performs** — `$check`, 1-based — and never
 * a count of checks already made. That is deliberately the opposite of
 * {@see SettlePayouts::$lagAttempt}, which counts checks *done* because its first look
 * is immediate; copying that convention here would silently put the first question a
 * rung late. `delayBefore($n)` is the only place the index arithmetic lives, and it is
 * a pure function so a test can pin all three of its edges.
 *
 * Consequence worth stating: `$check` cannot run past the end of the ladder. The last
 * check computes a next delay of `null`, alerts, and stops — so **one payout gets one
 * "unconfirmed" email**, structurally, rather than because a guard happens to hold.
 *
 * ## One chain per payout, and why no dedupe key is needed
 *
 * `forPayout()` is called from the one method that writes a row to `processing`, and
 * nothing in the platform ever puts a row back there — so a payout's ladder starts once
 * in its lifetime. A webhook that lands mid-ladder simply wins: every terminal write
 * claims the row with a conditional update, and the next check finds a row that is no
 * longer `processing` and answers `skipped`.
 *
 * The operator's **Check now** is deliberately *not* a second entry point. It calls
 * `checkPayout()` inline instead, because a second chain would run to its own end and
 * send a second alert about a payout somebody had just looked at by hand.
 *
 * ## The budget is the ladder, not the worker
 *
 * `tries()` is 1. The provider question is one call, and `resolve()` already turns a
 * refused, unreachable or unanswerable provider into an answer rather than an
 * exception — so a retry of this job would be a second question asked at a time the
 * ladder did not choose, racing the schedule that was. What `tries() = 1` must not do
 * is fail silently: see `failed()`.
 *
 * ## Standing down on a sync queue
 *
 * `forPayout()` and `checkLater()` both return early when the queue cannot defer
 * (`SyncQueue`), because on that driver `delay()` is ignored: the "delayed" job would
 * run inline, inside the customer's checkout, and then recurse down the whole ladder in
 * one call. A sync environment keeps exactly the behaviour it had before this existed,
 * which is the webhook and a manual `custody:payouts`. `Queue::fake()` is not caught by
 * either check — `QueueFake::connection()` returns the fake, not the driver underneath —
 * which is what lets the tests drive the ladder for real.
 */
final class CheckPayoutStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Which check this job performs, 1-based.
     *
     * **A declared property with a default, deliberately not a promoted one**, for the
     * reason set out on {@see SettlePayouts::$lagAttempt}: a job already sitting in the
     * `jobs` table when a field is added deserialises without it, and a promoted typed
     * property would then be uninitialised and throw on the first read. Declared this
     * way it comes back as 1, which is exactly what it is — the first question.
     */
    public int $check = 1;

    /**
     * @param  int  $payoutId  the row to ask about
     * @param  int  $check  which question this is, 1-based
     */
    public function __construct(
        public readonly int $payoutId,
        int $check = 1,
    ) {
        $this->check = max(1, $check);
    }

    /**
     * The gap list, in seconds, oldest first — one entry per question asked.
     *
     * The fallback repeats the shipped default rather than trusting the config to be
     * non-empty, matching {@see SettlePayouts::backoff()}: a config that resolved to
     * nothing should mean "the default schedule", never "ask forever" or "never ask".
     *
     * @return array<int, int>
     */
    public static function schedule(): array
    {
        $seconds = config('custody.payout_status_backoff_seconds', []);

        return is_array($seconds) && $seconds !== []
            ? array_values(array_map(static fn ($seconds): int => max(1, (int) $seconds), $seconds))
            : [5, 15, 30, 60, 120, 300, 900, 1800, 3600];
    }

    /**
     * How long to wait before question number `$check`, or null when the ladder has no
     * such question — which is how a chain knows it is finished.
     */
    public static function delayBefore(int $check): ?int
    {
        return self::schedule()[$check - 1] ?? null;
    }

    /**
     * Start the ladder for a payout the provider has just accepted.
     *
     * Called from {@see PayoutSweepService::apply()}, which runs on the customer's
     * payment path, so this never throws: a queue that is down must not turn a
     * successful sale into an error. The failure is logged rather than swallowed
     * silently, exactly as {@see SettlePayouts} does for the same reason.
     */
    public static function forPayout(Payout $payout): void
    {
        // A queue that cannot defer cannot check later. See the class docblock: without
        // this, the first question would be asked inline inside the checkout and the
        // ladder would recurse through itself in that one call.
        if (Queue::connection() instanceof SyncQueue) {
            return;
        }

        try {
            self::dispatch((int) $payout->id, 1)
                ->delay(now()->addSeconds(max(1, (int) self::delayBefore(1))));

            Log::info('custody.payout.check_queued', [
                'payout_id' => $payout->id,
                'reference' => $payout->reference,
                'in_seconds' => self::delayBefore(1),
                'checks_scheduled' => count(self::schedule()),
            ]);
        } catch (Throwable $e) {
            Log::channel('queue')->warning('custody.payout.check_not_queued', [
                'payout_id' => $payout->id,
                'reference' => $payout->reference,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The ladder is the retry, so the worker's budget is one attempt per question.
     *
     * {@see self::failed()} is what makes that safe to say: with a single attempt, an
     * exception here would otherwise end the chain with no trace at all.
     */
    public function tries(): int
    {
        return 1;
    }

    public function handle(PayoutSweepService $sweep, PlatformPayoutNotifier $platform): void
    {
        $payout = Payout::query()->find($this->payoutId);

        // The row is gone, so there is nothing to ask about and nobody to tell.
        if (! $payout instanceof Payout) {
            return;
        }

        $result = $sweep->checkPayout($payout);

        // One line per question, and it carries the three things needed to read the
        // history back later: which question this was, how many there will be, and what
        // the provider said. `result` is the only place `skipped` and `unresolved` are
        // distinguishable — both leave the row alone, and only one of them means the
        // provider was actually asked.
        Log::info('custody.payout.checked', [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'business_id' => $payout->business_id,
            'check' => $this->check,
            'of' => count(self::schedule()),
            'result' => $result,
            'status' => $payout->fresh()?->status,
            'provider_payout_id' => $payout->provider_payout_id,
        ]);

        // Anything else is an answer rather than a stall: the row has been settled,
        // failed or returned, or another writer reached a final state first. Either way
        // this payout is finished and the ladder ends here.
        if ($result !== 'unresolved') {
            return;
        }

        $this->checkLater($payout, $platform);
    }

    /**
     * Ask again on the next rung, or tell a person that we have stopped asking.
     *
     * **A re-check is a new job, and it has to be.** The worker renders `tries()` and
     * `backoff()` into the payload when a job is pushed, so nothing `handle()` discovers
     * can put the next attempt an hour out. Waiting means pushing a job that will not run
     * until then — the same reasoning as {@see SettlePayouts::recheckLater()}.
     *
     * Deliberately not swallowing a failure to queue in the way `forPayout()` does.
     * There, the caller is a checkout that must not be turned into an error; here, a
     * check that cannot be queued and is not reported would end the chain silently with
     * the money unaccounted for, which is the one outcome this job exists to prevent. So
     * it reports, and the ladder ends. The row is still `processing`, still listed in the
     * admin queue, and still caught by a manual `custody:payouts`.
     */
    private function checkLater(Payout $payout, PlatformPayoutNotifier $platform): void
    {
        if (Queue::connection() instanceof SyncQueue) {
            return;
        }

        $next = $this->check + 1;
        $delay = self::delayBefore($next);

        if ($delay === null) {
            $this->alert($payout, $platform);

            return;
        }

        Log::info('custody.payout.checking', [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'check' => $next,
            'of' => count(self::schedule()),
            'next_check_in_seconds' => $delay,
        ]);

        try {
            // `delay()`, so the row is written now and becomes claimable later. That is
            // the difference between this and a `sleep()`: no worker is held open for an
            // hour, and the money is not waiting on a process staying alive.
            self::dispatch($this->payoutId, $next)->delay(now()->addSeconds($delay));
        } catch (Throwable $e) {
            Log::channel('queue')->warning('custody.payout.check_not_queued', [
                'payout_id' => $payout->id,
                'reference' => $payout->reference,
                'check' => $next,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Every question is spent and the provider never said it landed. Tell the admins.
     *
     * The figures are read from the row rather than accumulated, because the ladder
     * always runs to its end before reaching here: the number of questions asked is the
     * length of the list, and how long this has been open is `sent_at`. Nothing needs to
     * be persisted to say either.
     */
    private function alert(Payout $payout, PlatformPayoutNotifier $platform): void
    {
        $business = $payout->business;

        if (! $business instanceof Business) {
            return;
        }

        $platform->payoutUnconfirmed(
            business: $business,
            payout: $payout,
            checks: count(self::schedule()),
            since: $payout->sent_at ?? $payout->requested_at,
        );
    }

    /**
     * The job itself threw — a database error inside the write, most likely.
     *
     * Reached through the worker's failure path, which calls this on the job instance
     * and not through the container (`CallQueuedHandler::failed()` calls
     * `$command->failed($e)` directly). With `tries()` at 1 this is the last thing that
     * happens to the chain, so it is the only trace: the row stays `processing`, and a
     * log line is what says the ladder ended without reaching its alert. No mail here —
     * the alert is about the *provider* being silent, and this is us failing to ask.
     */
    public function failed(Throwable $e): void
    {
        Log::channel('queue')->error('custody.payout.check_failed', [
            'payout_id' => $this->payoutId,
            'check' => $this->check,
            'error' => $e->getMessage(),
            'exception' => $e::class,
        ]);
    }
}
