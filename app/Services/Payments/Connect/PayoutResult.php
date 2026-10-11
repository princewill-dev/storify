<?php

namespace App\Services\Payments\Connect;

/**
 * What the provider said about one withdrawal attempt.
 *
 * Two statuses are carried, and they answer different questions. `outcome` is
 * ours — was a payout created, was there nothing to do, did it get refused —
 * and it is what the sweep counts. `providerStatus` is the provider's own
 * lifecycle (`pending` → `processing` → `completed`|`failed`), and it is what
 * the console shows.
 *
 * They genuinely diverge, and collapsing them would be a lie: a payout that has
 * just been created is a **success** for us and `pending` for the provider, and
 * an account's first-ever payout sits there for a one-time review rather than
 * failing. Reporting `pending` as a failure would retry a withdrawal that is
 * already on its way.
 */
final class PayoutResult
{
    public const OUTCOME_PAID = 'paid';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_FAILED = 'failed';

    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $message = null,
        public readonly ?int $amountKobo = null,
        public readonly ?string $payoutId = null,
        public readonly ?string $reference = null,
        public readonly ?string $providerStatus = null,
        public readonly array $raw = [],
    ) {}

    /**
     * A payout was created. Not the same as the money having landed — that is
     * what `payout.paid` is for.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function paid(
        ?string $payoutId,
        int $amountKobo,
        string $reference,
        string $providerStatus,
        array $raw = [],
    ): self {
        return new self(
            self::OUTCOME_PAID,
            amountKobo: $amountKobo,
            payoutId: $payoutId,
            reference: $reference,
            providerStatus: $providerStatus,
            raw: $raw,
        );
    }

    /**
     * Nothing was sent, and nothing is wrong: no settled balance yet, no
     * destination registered, an identical payout already in flight. Recorded
     * rather than logged as an error, because the sweep runs over every account
     * and most of them will be skipped most of the time.
     */
    public static function skipped(string $message): self
    {
        return new self(self::OUTCOME_SKIPPED, $message);
    }

    public static function failed(string $message, ?int $amountKobo = null): self
    {
        return new self(self::OUTCOME_FAILED, $message, $amountKobo);
    }

    public function isFailure(): bool
    {
        return $this->outcome === self::OUTCOME_FAILED;
    }
}
