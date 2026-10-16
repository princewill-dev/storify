<?php

namespace App\Services\Payments\Payouts;

/**
 * What the provider gave us back for a destination we registered.
 *
 * Deliberately narrower than what it replaced. That carried a connected
 * account's whole state — capabilities, requirements, status — because an account
 * had to be *approved* before money could move, and the approval could lapse
 * after it had been. A destination has no such state: it is an address, it either
 * exists or it does not.
 *
 * `$usable` is kept anyway, because one provider does report it (`is_usable`), and
 * a destination the provider considers unusable will refuse the transfer later —
 * better to know at registration than after a payout run. Null means the provider
 * said nothing, which is not the same as "no".
 *
 * ## Refused and indeterminate are not the same answer
 *
 * The same distinction {@see TransferOutcome} draws, for the same reason. A
 * refused destination is the provider saying no — a wrong account number, a bank
 * it cannot pay — and that answer will not change by asking again. An
 * indeterminate one is the provider saying *nothing*: a connection that dropped,
 * a 5xx, a body without an id. It may well have registered the destination, and
 * asking again in five minutes is exactly what should happen.
 *
 * Collapsing the two is what turns a provider outage into a platform that tells
 * every business its bank account is unacceptable, and it is why the flag exists
 * rather than being inferred from a message string.
 */
final class PayoutDestinationRef
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly ?string $id,
        public readonly ?bool $usable = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
        public readonly bool $indeterminate = false,
    ) {}

    /**
     * Registered, or already registered. The id is what pays out later.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function registered(string $id, ?bool $usable = null, array $raw = []): self
    {
        return new self($id, $usable, raw: $raw);
    }

    /**
     * The provider would not take it — a wrong account number, a bank it cannot
     * pay, a missing scope on the platform key.
     *
     * No id, so nothing downstream can accidentally transfer to a half-made
     * destination. An implementation that returned an id here would produce a
     * payout row pointing at a destination that does not exist.
     */
    public static function refused(string $message, array $raw = []): self
    {
        return new self(null, null, $message, $raw);
    }

    /**
     * We did not get an answer, so we do not know whether the destination exists.
     *
     * Carries no id for the same reason a refusal does not: nothing may be sent to
     * a destination we cannot name. What separates it from a refusal is that the
     * caller must not read it as a verdict about the account — and must not fail a
     * business's payout account on the strength of it.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function indeterminate(string $message, array $raw = []): self
    {
        return new self(null, null, $message, $raw, true);
    }

    public function ok(): bool
    {
        return $this->id !== null && $this->id !== '';
    }

    /**
     * Registered, and the provider did not object to it.
     *
     * Unusable-when-reported is a refusal in disguise: it means the registration
     * succeeded and the money still would not go, so the caller must not treat a
     * green light as a green light.
     */
    public function payable(): bool
    {
        return $this->ok() && $this->usable !== false;
    }
}
