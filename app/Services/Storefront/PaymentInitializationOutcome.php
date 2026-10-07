<?php

namespace App\Services\Storefront;

/**
 * Outcome of a Paystack initialization attempt.
 *
 * Either the charge was initialized (carrying the three scalars the response
 * echoes), the gateway itself refused it (its message is the 502 body), or an
 * exception rolled the request back (the controller answers 500). Keeping the
 * distinction here keeps the HTTP message strings and status codes in the
 * controller.
 */
final readonly class PaymentInitializationOutcome
{
    private function __construct(
        public bool $initialized,
        public ?string $authorizationUrl,
        public ?string $reference,
        public ?float $amount,
        public ?string $gatewayMessage,
        public bool $unexpectedFailure,
    ) {}

    public static function initialized(?string $authorizationUrl, string $reference, float $amount): self
    {
        return new self(true, $authorizationUrl, $reference, $amount, null, false);
    }

    public static function gatewayFailed(?string $message): self
    {
        return new self(false, null, null, null, $message, false);
    }

    public static function failed(): self
    {
        return new self(false, null, null, null, null, true);
    }
}
