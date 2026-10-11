<?php

namespace App\Services\Subscription;

/**
 * The outcome of verifying a payment against the gateway it was made through,
 * carrying the compared values so the caller can log exactly what disagreed.
 */
final readonly class GatewayVerificationResult
{
    /**
     * @param  array<string, mixed>  $gateway  the provider's own payload, stored
     *                                         verbatim as `gateway_response`
     * @param  string|null  $providerReference  the name the provider knows this
     *                                          payment by, which is what
     *                                          `gateway_reference` records. Not
     *                                          always `$gateway['id']`: a gateway
     *                                          that reports a charge inside a
     *                                          checkout has two ids, and the one
     *                                          its deliveries quote is the outer
     *                                          one the payload calls something
     *                                          else.
     */
    public function __construct(
        public bool $verified,
        public array $gateway,
        public int $expectedKobo,
        public int $verifiedKobo,
        public string $verifiedCurrency,
        public ?string $providerReference = null,
    ) {}
}
