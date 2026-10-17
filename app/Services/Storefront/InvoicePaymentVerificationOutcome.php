<?php

namespace App\Services\Storefront;

use App\Models\Invoice;

/**
 * Outcome of an invoice payment verification attempt.
 *
 * Verified carries the refreshed invoice the response payload is built from;
 * otherwise the gateway's own message is returned for the controller's 402.
 * Deliberately a sibling of {@see PaymentVerificationOutcome} rather than a
 * reuse: that one is `Order`-shaped, and an invoice has no order to hand back.
 */
final readonly class InvoicePaymentVerificationOutcome
{
    private function __construct(
        public bool $verified,
        public ?Invoice $invoice,
        public ?string $message,
    ) {}

    public static function verified(Invoice $invoice): self
    {
        return new self(true, $invoice, null);
    }

    public static function failed(?string $message): self
    {
        return new self(false, null, $message);
    }
}
