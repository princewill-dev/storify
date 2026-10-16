<?php

namespace App\Services\Payments\Payouts;

/**
 * What came back from one attempt to move money, in three answers rather than two.
 *
 * **This is the difference between a delayed payout and a double payment**, so the
 * distinction is drawn here rather than left to each caller to remember.
 *
 * - `accepted` — the provider took it. The money is on its way.
 * - `refused` — the provider answered, and the money did not move. Only this
 *   outcome permits the caller to release the wallet reservation, because only
 *   here is it *known* that nothing left.
 * - `indeterminate` — we asked and did not find out. A connection reset, a read
 *   timeout, a 502 from a proxy. The provider may have moved the money and simply
 *   failed to tell us.
 *
 * The trap this exists to close: a timeout is not a refusal. A rail that reported
 * one as a failure would have the caller release the reservation and try again —
 * and if the first attempt did in fact go through, the second is a genuine second
 * payment out of Storify's balance, to a business that was already paid. Nothing
 * would surface it: two rows, both plausible, both "successful", for one debt.
 *
 * So an indeterminate attempt leaves the reservation **in place** and leaves the
 * row `processing`, and the payout is resolved by asking the provider again
 * ({@see PayoutRail::lookUp()}) or by waiting for its webhook. Money we are unsure
 * about stays spent until we learn otherwise; that is the only safe direction to
 * be wrong in.
 *
 * Narrower than the result object it replaced in one other way: that one carried
 * `skipped` — "there was nothing to send, and that is not a failure" — because the
 * send it served read a *provider* balance and most accounts were empty most of
 * the time. The send that uses these rails reads a wallet Storify holds itself,
 * so it knows before it calls whether there is anything to send. A skip is decided
 * above this layer, and a rail returning one would be answering a question it was
 * not asked.
 */
final class TransferOutcome
{
    /** The provider took it. On its way, not arrived. */
    public const OUTCOME_ACCEPTED = 'accepted';

    /** The provider answered and did not move the money. Safe to release. */
    public const OUTCOME_REFUSED = 'refused';

    /** We asked and did not find out. The reservation must stay spent. */
    public const OUTCOME_INDETERMINATE = 'indeterminate';

    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $transferId = null,
        public readonly ?string $providerStatus = null,
        public readonly ?string $message = null,
        public readonly ?string $failureCode = null,
        public readonly array $raw = [],
    ) {}

    /**
     * The provider accepted the transfer. Not the same as the money landing —
     * that is what the `payout.paid` webhook is for.
     *
     * `$providerStatus` is kept verbatim rather than mapped, so a status we have
     * not seen before surfaces as itself instead of being flattened into the
     * nearest constant we happen to have.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function accepted(
        ?string $transferId,
        ?string $providerStatus = null,
        array $raw = [],
    ): self {
        return new self(self::OUTCOME_ACCEPTED, $transferId, $providerStatus, raw: $raw);
    }

    /**
     * The provider refused it. **The only outcome that means the money did not
     * move**, and therefore the only one a caller may release a reservation on.
     *
     * `$failureCode` is the provider's own code where it gave one — the only thing
     * that separates "the account number is wrong" from "our key lacks a scope"
     * from "the balance is short". Those have three different remedies and would
     * otherwise all read as "the payout failed".
     *
     * @param  array<string, mixed>  $raw
     */
    public static function refused(
        string $message,
        ?string $failureCode = null,
        array $raw = [],
    ): self {
        return new self(self::OUTCOME_REFUSED, null, null, $message, $failureCode, $raw);
    }

    /**
     * We do not know whether the money moved.
     *
     * Deliberately not a failure and deliberately not a success: it is the absence
     * of an answer. The caller keeps the reservation and resolves the payout by
     * lookup or webhook.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function indeterminate(string $message, array $raw = []): self
    {
        return new self(self::OUTCOME_INDETERMINATE, null, null, $message, null, $raw);
    }

    public function isAccepted(): bool
    {
        return $this->outcome === self::OUTCOME_ACCEPTED;
    }

    /**
     * Whether the provider definitively did not move the money.
     *
     * Phrased as a positive question on purpose. The dangerous call is the one
     * that releases a reservation, and a caller writing `if ($outcome->isRefused())`
     * is asking the right question — where `if (! $outcome->isAccepted())` would
     * silently sweep the indeterminate case into the same branch.
     */
    public function isRefused(): bool
    {
        return $this->outcome === self::OUTCOME_REFUSED;
    }

    public function isIndeterminate(): bool
    {
        return $this->outcome === self::OUTCOME_INDETERMINATE;
    }

    /**
     * Whether the payout is finished and the reservation must be kept.
     *
     * True for both `accepted` and `indeterminate` — the two cases where money may
     * be in flight — which is exactly why it is not named `isFailure()`.
     */
    public function isUnresolved(): bool
    {
        return ! $this->isRefused();
    }
}
