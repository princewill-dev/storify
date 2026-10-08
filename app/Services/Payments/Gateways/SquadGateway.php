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
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Squad (GTCO).
 *
 * Takes amounts in **kobo**, like Paystack and unlike Flutterwave, so the
 * intent's minor units go over the wire unchanged. Sandbox keys are prefixed
 * `sandbox_sk_` and the sandbox lives on a different host, so the base URL is
 * chosen from the key rather than configured — getting that wrong would send
 * test traffic at live money.
 */
final class SquadGateway implements PaymentGateway
{
    private const LIVE_URL = 'https://api-d.squadco.com';

    private const SANDBOX_URL = 'https://sandbox-api-d.squadco.com';

    public function code(): string
    {
        return 'squad';
    }

    public function currency(): string
    {
        return 'NGN';
    }

    public function initialize(PaymentIntent $intent, GatewayCredentials $credentials): InitializationResult
    {
        $response = $this->client($credentials)->post($this->baseUrl($credentials).'/transaction/initiate', [
            // Kobo, unchanged — this is where a units bug would be silent.
            'amount' => $intent->amountMinor,
            'email' => $intent->email,
            'currency' => $intent->currency,
            'initiate_type' => 'inline',
            'transaction_ref' => $intent->reference,
            'callback_url' => $intent->callbackUrl,
            'customer_name' => $intent->customerName,
            'payment_channels' => ['card', 'bank', 'ussd', 'transfer'],
        ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['success'] ?? false) !== true) {
            return InitializationResult::failed($body['message'] ?? 'Squad could not start this payment.', $body);
        }

        return InitializationResult::redirect(
            redirectUrl: (string) ($body['data']['checkout_url'] ?? ''),
            providerReference: $intent->reference,
            raw: $body['data'] ?? [],
        );
    }

    public function verify(string $reference, GatewayCredentials $credentials): VerificationResult
    {
        $response = $this->client($credentials)
            ->get($this->baseUrl($credentials).'/transaction/verify/'.$reference);

        $body = $response->json() ?? [];
        $data = $body['data'] ?? [];

        if (! $response->successful()) {
            return VerificationResult::failed($body['message'] ?? 'Squad could not verify this payment.', $body);
        }

        // Squad reports the status inside `data` and spells it `Success`.
        return match (strtolower((string) ($data['transaction_status'] ?? ''))) {
            'success', 'successful' => VerificationResult::paid(
                amountMinor: (int) ($data['transaction_amount'] ?? 0),
                currency: $data['currency'] ?? 'NGN',
                providerId: isset($data['transaction_ref']) ? (string) $data['transaction_ref'] : null,
                raw: $data,
            ),
            'pending', 'processing' => VerificationResult::pending('This payment has not completed yet.', $data),
            default => VerificationResult::failed('This payment was not successful.', $data),
        };
    }

    public function refund(RefundRequest $refund, GatewayCredentials $credentials): RefundResult
    {
        $response = $this->client($credentials)->post($this->baseUrl($credentials).'/transaction/refund', [
            'transaction_ref' => $refund->reference,
            'refund_type' => 'Full',
            'reason_for_refund' => $refund->reason,
        ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['success'] ?? false) !== true) {
            return RefundResult::failed($body['message'] ?? 'Squad refused this refund.', $body);
        }

        return RefundResult::ok(status: $body['data']['transaction_status'] ?? null, raw: $body['data'] ?? []);
    }

    public function parseWebhook(WebhookRequest $request, GatewayCredentials $credentials): ?WebhookEvent
    {
        $signature = (string) $request->header('x-squad-signature', '');

        if ($signature === '') {
            return null;
        }

        $expected = hash_hmac('sha512', $request->payload(), (string) $credentials->get('secret_key'));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        // Squad wraps everything in `Body`, capitalised — not `data`.
        $payload = $request->json();
        $data = $payload['Body'] ?? [];

        return new WebhookEvent(
            type: match ($payload['Event'] ?? null) {
                'charge_successful' => WebhookEventType::PAYMENT_SUCCESS,
                'charge_failed' => WebhookEventType::PAYMENT_FAILED,
                'refund_completed' => WebhookEventType::REFUND,
                default => WebhookEventType::IGNORED,
            },
            reference: isset($data['transaction_ref']) ? (string) $data['transaction_ref'] : null,
            providerId: isset($data['transaction_ref']) ? (string) $data['transaction_ref'] : null,
            amountMinor: isset($data['transaction_amount']) ? (int) $data['transaction_amount'] : null,
            currency: $data['currency'] ?? null,
            raw: $data,
        );
    }

    public function testConnection(GatewayCredentials $credentials): ConnectionResult
    {
        try {
            // A deliberately unknown reference: 404 means the key was accepted.
            $response = $this->client($credentials)
                ->get($this->baseUrl($credentials).'/transaction/verify/connection-test');

            if ($response->status() === 401) {
                return ConnectionResult::failed('Squad rejected this secret key.');
            }

            return in_array($response->status(), [200, 404], true)
                ? ConnectionResult::ok('Connected to Squad.')
                : ConnectionResult::failed('Squad answered with '.$response->status().'.');
        } catch (\Throwable $e) {
            Log::warning('squad.test_failed', ['error' => $e->getMessage()]);

            return ConnectionResult::failed('Could not reach Squad.');
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

    /**
     * Sandbox and live are different hosts as well as different keys, so the
     * key prefix decides which one this connection talks to.
     */
    private function baseUrl(GatewayCredentials $credentials): string
    {
        $key = (string) $credentials->get('secret_key');

        return str_starts_with($key, 'sandbox_') ? self::SANDBOX_URL : self::LIVE_URL;
    }

    private function client(GatewayCredentials $credentials): PendingRequest
    {
        return Http::withToken((string) $credentials->get('secret_key'))
            ->timeout(30)
            ->acceptJson();
    }
}
