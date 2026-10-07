<?php

namespace App\Services\Storefront;

use App\Models\Order;

/**
 * Outcome of a Paystack verification attempt.
 *
 * Verified carries the refreshed order the success payload is built from;
 * otherwise the gateway's own message is returned for the controller's 402.
 */
final readonly class PaymentVerificationOutcome
{
    private function __construct(
        public bool $verified,
        public ?Order $order,
        public ?string $message,
    ) {}

    public static function verified(Order $order): self
    {
        return new self(true, $order, null);
    }

    public static function failed(?string $message): self
    {
        return new self(false, null, $message);
    }
}
