<?php

namespace App\Services\Payments\Contracts;

use App\Services\Payments\Data\ConnectionResult;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\InitializationResult;
use App\Services\Payments\Data\PaymentIntent;
use App\Services\Payments\Data\RefundRequest;
use App\Services\Payments\Data\RefundResult;
use App\Services\Payments\Data\VerificationResult;
use App\Services\Payments\Data\WebhookEvent;
use App\Services\Payments\Data\WebhookRequest;

/**
 * One payment provider.
 *
 * Credentials arrive per call and are never held. A driver must be stateless —
 * no cached tokens on the instance, no mutable key fields — because the
 * container resolves these into long-lived workers, and the legacy
 * `PaystackService::usingGateway()` demonstrates exactly what that costs: the
 * second business processed in one request inherits the first business's keys.
 *
 * Manual bank transfer implements this too. It makes no HTTP call; its
 * `initialize()` returns OFFLINE with instructions and its `verify()` returns
 * PENDING, because a person confirms it. That is why the interface has an
 * outcome *mode* rather than a branch on provider name.
 */
interface PaymentGateway
{
    /** The registry key, e.g. `paystack`. */
    public function code(): string;

    /** ISO 4217. A connection is only offered to a store charging the same currency. */
    public function currency(): string;

    public function initialize(PaymentIntent $intent, GatewayCredentials $credentials): InitializationResult;

    public function verify(string $reference, GatewayCredentials $credentials): VerificationResult;

    public function refund(RefundRequest $refund, GatewayCredentials $credentials): RefundResult;

    /**
     * Verify the signature and normalise the payload, or return null when it
     * does not match these credentials. Null is how the webhook controller
     * walks the candidate connections to find whose webhook this is.
     */
    public function parseWebhook(WebhookRequest $request, GatewayCredentials $credentials): ?WebhookEvent;

    public function testConnection(GatewayCredentials $credentials): ConnectionResult;

    public function supportsRefund(): bool;

    /**
     * Whether a customer may pay part of an order now. The storefront's amount
     * input is gated on this — offering a partial payment to a provider that
     * cannot take one produces a charge the provider rejects.
     */
    public function supportsPartialPayment(): bool;
}
