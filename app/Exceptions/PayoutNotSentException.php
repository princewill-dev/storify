<?php

namespace App\Exceptions;

use App\Jobs\SettlePayouts;
use App\Models\Business;
use RuntimeException;

/**
 * A payout could not be made, and the attempt budget is what runs out next.
 *
 * Thrown by {@see SettlePayouts} rather than returned as a status, and the throw is
 * the mechanism rather than a style choice: **the worker reads `backoff()` and
 * `tries()` only on the failure path** (`Worker::calculateBackoff()` and
 * `markJobAsFailedIfWillExceedMaxAttempts()` are both reached from the exception
 * handler). So throwing is what puts the gap from `custody.retry_backoff_minutes`
 * between one attempt and the next, and what makes the final attempt call the job's
 * `failed()` — where the admins are told. A job that returned a status instead would
 * look successful to the queue and never be tried again, which is the one outcome
 * worth this much machinery to avoid.
 *
 * It renders nothing. It is thrown inside a queued job, so
 * `Illuminate\Queue\CallQueuedHandler` catches it and marks the job failed long
 * before any HTTP handler could see it; if one ever did, a 500 would be right.
 *
 * **Nothing has been lost when this is thrown.** Either the provider refused and the
 * money was credited back, or no transfer was ever reserved because the provider
 * could not be read. In both cases the money is in the business's wallet, owed and
 * visible on its own screen, and pressing Retry in the console sends it.
 */
class PayoutNotSentException extends RuntimeException
{
    public function __construct(
        public readonly Business $business,
        /** Why it was not sent: a refusal, a provider we could not read, or a balance left behind. */
        public readonly string $reason,
        /** The attempt that just finished, and the budget it finished against. */
        public readonly int $attempt,
        public readonly int $of,
    ) {
        parent::__construct(sprintf(
            'Payout for business #%d was not sent (%s) — attempt %d of %d.',
            $business->id,
            $reason,
            $attempt,
            $of,
        ));
    }
}
