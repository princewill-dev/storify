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
     * @param  string|null  $webhookUrl  where the provider should send payment
     *                                   alerts. Not the same thing as
     *                                   `callbackUrl`, which is where the
     *                                   customer's browser goes back to. Most
     *                                   providers are configured with this in
     *                                   their dashboard and have no use for it
     *                                   here; Korapay takes it per payment.
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
        public readonly ?string $webhookUrl = null,
    ) {}
}
