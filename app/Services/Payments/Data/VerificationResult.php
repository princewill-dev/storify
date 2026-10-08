<?php

namespace App\Services\Payments\Data;

use App\Enums\VerificationStatus;

/**
 * A provider's answer about one payment.
 *
 * `amountMinor` is what the provider says was actually paid, which is not
 * necessarily what we asked for. Callers must compare it against the expected
 * amount themselves using `Naira::koboFromStrict` — underpayment is a real
 * outcome and confirming an order on a mismatched amount is a security problem,
 * not a rounding detail.
 */
final class VerificationResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public readonly VerificationStatus $status,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly ?string $providerId = null,
        public readonly ?int $feesMinor = null,
        public readonly ?string $paidAt = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}

    public static function paid(int $amountMinor, ?string $currency = null, ?string $providerId = null, ?int $feesMinor = null, ?string $paidAt = null, array $raw = []): self
    {
        return new self(
            status: VerificationStatus::PAID,
            amountMinor: $amountMinor,
            currency: $currency,
            providerId: $providerId,
            feesMinor: $feesMinor,
            paidAt: $paidAt,
            raw: $raw,
        );
    }

    public static function pending(?string $message = null, array $raw = []): self
    {
        return new self(status: VerificationStatus::PENDING, message: $message, raw: $raw);
    }

    public static function failed(?string $message = null, array $raw = []): self
    {
        return new self(status: VerificationStatus::FAILED, message: $message, raw: $raw);
    }

    public function isPaid(): bool
    {
        return $this->status === VerificationStatus::PAID;
    }
}
