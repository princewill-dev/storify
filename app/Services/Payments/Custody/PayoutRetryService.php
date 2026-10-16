<?php

namespace App\Services\Payments\Custody;

use App\Jobs\SettlePayouts;
use App\Models\Business;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The operator's "try that again" for payouts the platform has given up on.
 *
 * ## What this is allowed to do, and what it is not
 *
 * An admin route that moves money is a shape this codebase spent a lot of effort
 * removing, so the boundary is worth stating precisely. This does **not** choose an
 * amount, a destination or a moment. It does not create a debt, cancel one, or
 * change one. It clears the platform's memory of *refusals* and then dispatches the
 * same {@see SettlePayouts} job a collected sale dispatches, which reserves and
 * sends whatever the wallet already says is owed to the account the business already
 * named.
 *
 * The difference between this and a "pay this business now" button is the whole
 * point: this can only make the platform attempt a payment it was already trying to
 * make, and the amount sent is decided by the wallet either way. If a business is
 * owed nothing, or is on hold, or names no bank, the retry sends nothing —
 * `settle()` refuses for exactly the reasons it always does.
 *
 * It is therefore the natural completion of the ceiling: {@see
 * PayoutSweepService::retriesExhausted()} stops the platform asking automatically,
 * and something has to be able to start it again once a person has looked, or the
 * ceiling would be a trapdoor. Before this, the only lever was
 * `custody:retry-payouts` on the command line, which is not a lever an operator has
 * at 2am from a phone.
 *
 * ## Why clearing is a precondition and not an option
 *
 * Clearing is not a way to skip the guard rails — it is the only way through them,
 * and it is deliberately visible. `payoutBlocker()` reads the ceiling off the
 * `failed` rows themselves, so the ceiling cannot be lifted without retiring those
 * rows, and retiring them is a `cancelled` status with
 * `meta.cancelled_reason = 'payout_retry_authorised'` on every one. An operator's
 * intervention is therefore written into the same record that raises the ceiling,
 * and the next person to look at the payout history can see that a human authorised
 * the attempt rather than that the failures quietly disappeared.
 *
 * ## Why it dispatches, and what that changed
 *
 * **It used to call {@see PayoutSweepService::sweepBusiness()} inline, and that was
 * wrong for a reason that is easy to miss: `sweepBusiness()` never reads the
 * promotion gate.** It sends what is already in `available_balance`, so money the
 * gate left in `pending_balance` — which is exactly where a provider outage leaves
 * it, the gate having fallen back to its 72-hour floor — is invisible to it. An
 * operator pressing Retry after an outage would have got "settled", nothing sent,
 * and a business still unpaid behind a button that appeared to work.
 *
 * The job goes through {@see InstantPayoutService::settle()}, which matures first
 * and sends second, so promotion travels with sending on every path. That is the
 * whole of the fix, and it is why this is not a "no maturation step" case any more.
 *
 * The dispatch is off the request thread for the reason the collection path is: an
 * operator's click should not wait on a provider round trip, and this deployment
 * keeps a `queue:work` running — which is load-bearing for money now, not just for
 * email. The answer to the operator is that the attempt is *queued*; whether it
 * worked is on the business's payout history, and pressing again is cheap.
 *
 * Note what is deliberately *not* done here: no `Queue::fake()`-style optimism, no
 * delayed re-dispatch, and no second attempt counter of its own. The job brings its
 * own retries and its own alert, so a retry that fails behaves exactly like the
 * original send that failed.
 */
final class PayoutRetryService
{
    /**
     * How many businesses one retry-all will even look at, before it decides which
     * of them are actually stalled.
     *
     * Wider than the number it will act on, because the candidates come from the
     * payouts table and the answer comes from {@see PayoutSweepService::payoutBlocker()}
     * — so a batch's worth of candidates can contain businesses that stopped being
     * stalled since they were read. This is a bound on the *read*, not a promise
     * about the write.
     */
    private const CANDIDATE_FACTOR = 3;

    public function __construct(
        private readonly PayoutSweepService $sweep,
    ) {}

    /**
     * Clear one business's refusals, then queue the attempt.
     *
     * Cleared and dispatched in the same request, in that order and for a reason: the
     * job reads the ceiling off the failure rows, so a job queued before they were
     * retired would find itself blocked by the very failures the operator has just
     * looked at. Clearing first is also what opens the send's backoff gate, which is
     * measured from those same rows — so the retry does not have to ask for that gate
     * to be ignored, and a person's click is never held behind a clock.
     *
     * @return array{cleared: int, queued: bool, blocker: array{code: string, label: string}|null}
     */
    public function retryBusiness(Business $business, ?User $actor = null): array
    {
        $cleared = $this->sweep->clearFailures($business);

        // Read *after* clearing, so it describes the state the send is about to meet
        // rather than the state that was just retired. A business on hold, or with no
        // bank, still comes back blocked here — clearing failures never bypassed that.
        $blocker = $this->sweep->payoutBlocker($business);

        // Nothing is queued for a blocked business: there is no attempt that could
        // send, so a job would be a provider round trip spent to learn what the
        // blocker already says. The operator gets the reason back instead.
        if ($blocker === null) {
            SettlePayouts::forBusiness((int) $business->id);
        }

        Log::warning('custody.payout.operator_retry', [
            'business_id' => $business->id,
            'actor_id' => $actor?->id,
            'cleared' => $cleared,
            'queued' => $blocker === null,
            'blocker' => $blocker['code'] ?? null,
        ]);

        return ['cleared' => $cleared, 'queued' => $blocker === null, 'blocker' => $blocker];
    }

    /**
     * The same thing for every business whose sends have stopped.
     *
     * ## Who is a candidate
     *
     * Every business holding a `failed` payout that no `paid` payout has come after
     * — which is the cross-business form of the predicate
     * {@see PayoutSweepService::consecutiveFailures()} counts, and the reason a
     * business that failed twice and then succeeded is not here at all. Deliberately
     * not time-bounded: the per-business window has no time bound either, so a stall
     * from three weeks ago still blocks that business and a lookback here would make
     * the one button that fixes it silently skip the ones that need it most.
     *
     * ## Who is acted on
     *
     * Only those a ceiling is actually stopping, tested with the same
     * {@see PayoutSweepService::payoutBlocker()} the wallet and the queue read. A
     * candidate whose blocker is a hold, a switched-off automatic payout or a missing
     * bank is counted as `blocked` and left alone: those are states a person put the
     * business in, nothing failed because of them, and retrying would be a provider
     * call that could not possibly send.
     *
     * ## Batched, and says so
     *
     * Bounded by `custody.payout_batch`, the same working-set bound the send uses.
     * `truncated` is set when the candidate read hit its limit, which is the only
     * signal that matters here: with more stalled businesses than one press handles,
     * the operator presses again, and being told that is better than a button that
     * quietly did a fraction of what it said.
     *
     * @return array{considered: int, retried: int, blocked: int, cleared: int, queued: int, truncated: bool, results: array<int, array<string, mixed>>}
     */
    public function retryAll(?User $actor = null): array
    {
        $batch = max(1, (int) config('custody.payout_batch', 100));
        $candidates = $this->stalledCandidates(min($batch * self::CANDIDATE_FACTOR, 500));

        $tally = [
            'considered' => 0,
            'retried' => 0,
            'blocked' => 0,
            'cleared' => 0,
            // What was handed to the queue. There is no `sent`/`failed` here any more
            // and their absence is the change, not an omission: the attempts happen on
            // a worker after this returns, so a count of them would be a guess. What an
            // operator gets is the batch's own honest answer — how many were queued —
            // and the payout history answers the rest.
            'queued' => 0,
            'truncated' => false,
            'results' => [],
        ];

        $tally['truncated'] = count($candidates) >= min($batch * self::CANDIDATE_FACTOR, 500);

        foreach ($candidates as $id) {
            if ($tally['retried'] >= $batch) {
                break;
            }

            $business = Business::find($id);

            if (! $business instanceof Business) {
                continue;
            }

            $tally['considered']++;

            // Asked *before* clearing, unlike the single-business retry: here the
            // answer is what decides whether this business is in scope at all, and
            // clearing a business we then decline to retry would take away its
            // record of the failures without sending anything.
            $blocker = $this->sweep->payoutBlocker($business);

            // `RETRYABLE_BLOCKERS` rather than `STALLING_BLOCKERS`, and the difference
            // is the whole reason the batch button covers what it does. The stalling
            // list answers "should a human be told?", and it is deliberately narrow —
            // it predates the job and does not contain `settlement_failed`, which is
            // the blocker the job's own alert raises when a provider never answered.
            // Filtering on it here would have made the batch button skip precisely the
            // businesses the alert had just named, one line above the button.
            if ($blocker === null || ! in_array($blocker['code'], PayoutSweepService::RETRYABLE_BLOCKERS, true)) {
                $tally['blocked']++;

                continue;
            }

            $result = $this->retryBusiness($business, $actor);

            $tally['retried']++;
            $tally['cleared'] += $result['cleared'];
            $tally['queued'] += $result['queued'] ? 1 : 0;
            $tally['results'][] = [
                'business_id' => $business->id,
                'business_name' => $business->name,
                'business_code' => $business->business_code,
                'cleared' => $result['cleared'],
                'queued' => $result['queued'],
                'blocker' => $blocker['code'],
            ];
        }

        Log::warning('custody.payout.operator_retry_all', [
            'actor_id' => $actor?->id,
            'considered' => $tally['considered'],
            'retried' => $tally['retried'],
            'queued' => $tally['queued'],
            'truncated' => $tally['truncated'],
        ]);

        return $tally;
    }

    /**
     * Businesses holding a refusal that nothing since has redeemed.
     *
     * Written as the clause "no `paid` payout is newer than this `failed` one",
     * which is the same question `PayoutSweepService::failureQuery()` asks one
     * business at a time — expressed across businesses so it can be asked once. Two
     * shapes of the one idea, kept in step by the docblock rather than by a shared
     * builder: the per-business version is a scalar comparison against a `max()`,
     * because that is what an indexed lookup on `['business_id','status','created_at']`
     * wants, and folding the two into one correlated query would slow the common
     * case down to save a paragraph.
     *
     * @return array<int, int>
     */
    private function stalledCandidates(int $limit): array
    {
        return Payout::query()
            ->where('status', Payout::STATUS_FAILED)
            ->whereNotNull('business_id')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('payouts as redeeming')
                    ->whereColumn('redeeming.business_id', 'payouts.business_id')
                    ->where('redeeming.status', Payout::STATUS_PAID)
                    ->whereColumn('redeeming.created_at', '>', 'payouts.created_at');
            })
            ->distinct()
            ->orderBy('business_id')
            ->limit($limit)
            ->pluck('business_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
