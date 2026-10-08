<?php

namespace App\Services\Payments\Gateways;

use App\Enums\WebhookEventType;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\ConnectionResult;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\InitializationResult;
use App\Services\Payments\Data\PaymentIntent;
use App\Services\Payments\Data\RefundRequest;
use App\Services\Payments\Data\RefundResult;
use App\Services\Payments\Data\VerificationResult;
use App\Services\Payments\Data\WebhookEvent;
use App\Services\Payments\Data\WebhookRequest;
use App\Support\Money\Naira;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Korapay.
 *
 * Amounts travel in the major unit, and the webhook is signed HMAC-SHA256 over
 * the raw request body — unlike Paystack's SHA512 and Flutterwave's plain
 * hash comparison.
 */
final class KorapayGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.korapay.com/merchant/api/v1';

    public function code(): string
    {
        return 'korapay';
    }

    public function currency(): string
    {
        return 'NGN';
    }

    public function initialize(PaymentIntent $intent, GatewayCredentials $credentials): InitializationResult
    {
        $response = $this->client($credentials)->post(self::BASE_URL.'/charges/initialize', [
            'reference' => $intent->reference,
            'amount' => Naira::floatFromKobo($intent->amountMinor),
            'currency' => $intent->currency,
            'redirect_url' => $intent->callbackUrl,
            'notification_url' => $intent->callbackUrl,
            'narration' => $intent->description ?: 'Order payment',
            'customer' => array_filter([
                'email' => $intent->email,
                'name' => $intent->customerName,
            ]),
            // The business absorbs the fee by default. Sending it explicitly so
            // the choice is visible here rather than implied by a default that
            // could change under us.
            'merchant_bears_cost' => true,
        ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['status'] ?? null) !== true) {
            return InitializationResult::failed($body['message'] ?? 'Korapay could not start this payment.', $body);
        }

        return InitializationResult::redirect(
            redirectUrl: (string) ($body['data']['checkout_url'] ?? ''),
            providerReference: $intent->reference,
            raw: $body['data'] ?? [],
        );
    }

    public function verify(string $reference, GatewayCredentials $credentials): VerificationResult
    {
        $response = $this->client($credentials)->get(self::BASE_URL.'/charges/'.$reference);
        $body = $response->json() ?? [];
        $data = $body['data'] ?? [];

        if (! $response->successful() || ($body['status'] ?? null) !== true) {
            return VerificationResult::failed($body['message'] ?? 'Korapay could not verify this payment.', $body);
        }

        return match (strtolower((string) ($data['status'] ?? ''))) {
            'success' => VerificationResult::paid(
                amountMinor: Naira::koboFromRounded((float) ($data['amount'] ?? 0)),
                currency: $data['currency'] ?? 'NGN',
                providerId: isset($data['transaction_reference'])
                    ? (string) $data['transaction_reference']
                    : (isset($data['payment_reference']) ? (string) $data['payment_reference'] : null),
                raw: $data,
            ),
            'processing', 'pending' => VerificationResult::pending('This payment has not completed yet.', $data),
            default => VerificationResult::failed('This payment was not successful.', $data),
        };
    }

    public function refund(RefundRequest $refund, GatewayCredentials $credentials): RefundResult
    {
        $response = $this->client($credentials)->post(
            self::BASE_URL.'/charges/'.$refund->reference.'/refund',
            array_filter([
                'reason' => $refund->reason,
                'amount' => Naira::floatFromKobo($refund->amountMinor),
            ]),
        );

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['status'] ?? null) !== true) {
            return RefundResult::failed($body['message'] ?? 'Korapay refused this refund.', $body);
        }

        return RefundResult::ok(
            refundId: isset($body['data']['refund_reference']) ? (string) $body['data']['refund_reference'] : null,
            status: $body['data']['status'] ?? null,
            raw: $body['data'] ?? [],
        );
    }

    public function parseWebhook(WebhookRequest $request, GatewayCredentials $credentials): ?WebhookEvent
    {
        $signature = (string) $request->header('x-korapay-signature', '');

        if ($signature === '') {
            return null;
        }

        $expected = hash_hmac('sha256', $request->payload(), (string) $credentials->get('secret_key'));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $payload = $request->json();
        $data = $payload['data'] ?? [];

        return new WebhookEvent(
            type: match ($payload['event'] ?? null) {
                'charge.success' => WebhookEventType::PAYMENT_SUCCESS,
                'charge.failed' => WebhookEventType::PAYMENT_FAILED,
                'refund.successful' => WebhookEventType::REFUND,
                default => WebhookEventType::IGNORED,
            },
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            providerId: isset($data['transaction_reference']) ? (string) $data['transaction_reference'] : null,
            amountMinor: isset($data['amount']) ? Naira::koboFromRounded((float) $data['amount']) : null,
            currency: $data['currency'] ?? null,
            raw: $data,
        );
    }

    public function testConnection(GatewayCredentials $credentials): ConnectionResult
    {
        try {
            // Re-asking for a reference that cannot exist. A 401 means the key
            // is wrong; a 404 means it was accepted and the charge is unknown,
            // which is the answer we want.
            $response = $this->client($credentials)->get(self::BASE_URL.'/charges/connection-test');

            if ($response->status() === 401) {
                return ConnectionResult::failed('Korapay rejected this secret key.');
            }

            return in_array($response->status(), [200, 404], true)
                ? ConnectionResult::ok('Connected to Korapay.')
                : ConnectionResult::failed('Korapay answered with '.$response->status().'.');
        } catch (\Throwable $e) {
            Log::warning('korapay.test_failed', ['error' => $e->getMessage()]);

            return ConnectionResult::failed('Could not reach Korapay.');
        }
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function supportsPartialPayment(): bool
    {
        return true;
    }

    private function client(GatewayCredentials $credentials): PendingRequest
    {
        return Http::withToken((string) $credentials->get('secret_key'))
            ->timeout(30)
            ->acceptJson();
    }
}
