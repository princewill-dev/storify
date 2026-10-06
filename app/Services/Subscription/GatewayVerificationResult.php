<?php

namespace App\Services\Subscription;

/**
 * The outcome of double-verifying a payment against Paystack, carrying the
 * compared values so the caller can log exactly what disagreed.
 */
final readonly class GatewayVerificationResult
{
    /**
     * @param  array<string, mixed>  $gateway
     */
    public function __construct(
        public bool $verified,
        public array $gateway,
        public int $expectedKobo,
        public int $verifiedKobo,
        public string $verifiedCurrency,
    ) {}
}
