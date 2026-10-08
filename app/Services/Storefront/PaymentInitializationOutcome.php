<?php

namespace App\Services\Storefront;

/**
 * Outcome of a payment initialization attempt.
 *
 * Either the charge was initialized (carrying the three scalars the response
 * echoes), the gateway itself refused it (its message is the 502 body), or an
 * exception rolled the request back (the controller answers 500). Keeping the
 * distinction here keeps the HTTP message strings and status codes in the
 * controller.
 *
 * `mode` is what lets manual bank transfer travel this same path: a provider
 * that returns OFFLINE carries instructions instead of an authorization URL,
 * and the client renders them rather than redirecting. Without it the only way
 * to represent "there is nothing to visit" would be a null URL, and the client
 * would have to guess from the provider's name.
 */
final readonly class PaymentInitializationOutcome
{
    /**
     * @param  array<int, array<string, string>>  $instructions
     */
    private function __construct(
        public bool $initialized,
        public ?string $authorizationUrl,
        public ?string $reference,
        public ?float $amount,
        public ?string $gatewayMessage,
        public bool $unexpectedFailure,
        public string $mode = 'redirect',
        public array $instructions = [],
    ) {}

    /**
     * @param  array<int, array<string, string>>  $instructions
     */
    public static function initialized(
        ?string $authorizationUrl,
        string $reference,
        float $amount,
        string $mode = 'redirect',
        array $instructions = [],
    ): self {
        return new self(true, $authorizationUrl, $reference, $amount, null, false, $mode, $instructions);
    }

    /**
     * Nothing to visit — the customer is shown how to pay instead.
     *
     * @param  array<int, array<string, string>>  $instructions
     */
    public static function offline(string $reference, float $amount, array $instructions, ?string $message = null): self
    {
        return new self(true, null, $reference, $amount, $message, false, 'offline', $instructions);
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
