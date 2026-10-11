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
        /**
         * The connected account this charge **must** be created on, or null for
         * an ordinary charge.
         *
         * Declared here as well as being carried on the credentials because the
         * two are deliberately redundant: a provider that takes a direct charge
         * from a header will not reject the header's absence, so the request
         * silently succeeds as the platform and the money — and the refund
         * liability — land with us. Where the intent says "on this account" and
         * the credentials do not name that account, the driver refuses without
         * posting. One carrier alone cannot catch its own omission.
         */
        public readonly ?string $directChargeAccountId = null,
        /**
         * The platform's own cut of this charge, in minor units of `currency`,
         * taken by the provider out of the connected account's proceeds.
         *
         * Null means "no cut", which is not the same as zero: a platform that has
         * configured no commission sends no fee at all rather than a `0.00` line.
         */
        public readonly ?int $platformFeeMinor = null,
    ) {}
}
