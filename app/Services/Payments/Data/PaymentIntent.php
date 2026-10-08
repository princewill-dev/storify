<?php

namespace App\Services\Payments\Data;

/**
 * What we are asking a provider to charge.
 *
 * `amountMinor` is in the provider's smallest unit — kobo for every NGN
 * provider, cents for Bitfra's USD. Callers build it with `Naira` for NGN and
 * must not multiply inline; the codebase already has three separate
 * `(int) round($x * 100)` sites and a units bug here is silent and expensive.
 */
final class PaymentIntent
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $reference,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $email,
        public readonly string $callbackUrl,
        public readonly array $metadata = [],
        public readonly ?string $customerName = null,
        public readonly ?string $description = null,
    ) {}
}
