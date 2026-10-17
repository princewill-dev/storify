<?php

namespace App\Services\Payments\Payouts;

use RuntimeException;
use Throwable;

/**
 * We could not ask the provider anything at all.
 *
 * **This is not "the provider has no answer for this transfer".** That is what
 * {@see PayoutRail::lookUp()} returns `null` for, and it is a legitimate answer:
 * Bachs has no lookup by our reference, a just-created transfer may not be
 * readable yet, and a reference the provider has never seen is one race away
 * from a transfer it has. A `null` is safe to wait on.
 *
 * This exception is the other thing entirely: a connection that never
 * established, a read timeout, a 5xx, a rate limit. Nothing was learned about
 * *this* payout, which means nothing was learned about any other payout either —
 * the provider, not the transfer, is the problem. That distinction is what lets
 * {@see PayoutLookupBreaker} stop asking every other payout the same fruitless
 * question for the next minute, while still letting a genuine per-transfer
 * silence run its ladder to the end.
 *
 * It is deliberately an exception rather than a fourth {@see TransferOutcome}:
 * an outcome describes what happened to a *transfer*, and this describes why a
 * transfer was never discussed. Returning one would also make it look like an
 * answer to `resolve()`, which maps outcomes onto writes to the books.
 */
final class PayoutLookupUnreachable extends RuntimeException
{
    /**
     * The request never completed: a connection error, a timeout, a TLS failure.
     */
    public static function forProvider(string $provider, string $reference, Throwable $previous): self
    {
        return new self(
            sprintf('%s could not be reached to look up payout %s.', $provider, $reference),
            0,
            $previous,
        );
    }

    /**
     * The provider answered, but with a failure that says nothing about the
     * transfer — a 5xx, or a rate limit.
     *
     * A 4xx is deliberately *not* this: a 401, a 403 or a 404 is the provider
     * speaking about our request, and reporting it as an outage would let one
     * misconfigured key suppress every status check on the platform.
     */
    public static function forStatus(string $provider, string $reference, int $status): self
    {
        return new self(sprintf(
            '%s answered %d to a lookup of payout %s, which says nothing about the transfer.',
            $provider,
            $status,
            $reference,
        ));
    }
}
