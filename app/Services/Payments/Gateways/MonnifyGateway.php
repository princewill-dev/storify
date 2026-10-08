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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Monnify.
 *
 * The only provider here that needs **three** credentials and an OAuth
 * handshake: the API key and secret are exchanged for a short-lived access
 * token, and the contract code says which merchant account the money settles
 * into. A two-field form could not have connected it, which is why the
 * credential form is rendered from the registry rather than fixed.
 *
 * The token is cached rather than held on the instance. A driver is resolved
 * from the container and may live across many requests; keeping a token in a
 * property would let one connection's token leak into another business's call,
 * which is the same class of bug the Paystack client had.
 */
final class MonnifyGateway implements PaymentGateway
{
    private const LIVE_URL = 'https://api.monnify.com';

    private const SANDBOX_URL = 'https://sandbox.monnify.com';

    /** A minute under the provider's own expiry, so a token is never used as it lapses. */
    private const TOKEN_TTL_SECONDS = 3300;

    public function code(): string
    {
        return 'monnify';
    }

    public function currency(): string
    {
        return 'NGN';
    }

    public function initialize(PaymentIntent $intent, GatewayCredentials $credentials): InitializationResult
    {
        $response = $this->authenticated($credentials)
            ->post($this->baseUrl($credentials).'/api/v1/merchant/transactions/init-transaction', [
                'amount' => Naira::floatFromKobo($intent->amountMinor),
                'customerName' => $intent->customerName ?: 'Customer',
                'customerEmail' => $intent->email,
                'paymentReference' => $intent->reference,
                'paymentDescription' => $intent->description ?: 'Order payment',
                'currencyCode' => $intent->currency,
                'contractCode' => (string) $credentials->get('contract_code'),
                'redirectUrl' => $intent->callbackUrl,
                'paymentMethods' => ['CARD', 'ACCOUNT_TRANSFER'],
            ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['requestSuccessful'] ?? false) !== true) {
            return InitializationResult::failed(
                $body['responseMessage'] ?? 'Monnify could not start this payment.',
                $body,
            );
        }

        return InitializationResult::redirect(
            redirectUrl: (string) ($body['responseBody']['checkoutUrl'] ?? ''),
            providerReference: $intent->reference,
            raw: $body['responseBody'] ?? [],
        );
    }

    public function verify(string $reference, GatewayCredentials $credentials): VerificationResult
    {
        $response = $this->authenticated($credentials)
            ->get($this->baseUrl($credentials).'/api/v2/transactions/'.urlencode($reference));

        $body = $response->json() ?? [];
        $data = $body['responseBody'] ?? [];

        if (! $response->successful() || ($body['requestSuccessful'] ?? false) !== true) {
            return VerificationResult::failed(
                $body['responseMessage'] ?? 'Monnify could not verify this payment.',
                $body,
            );
        }

        return match (strtoupper((string) ($data['paymentStatus'] ?? ''))) {
            'PAID', 'OVERPAID' => VerificationResult::paid(
                amountMinor: Naira::koboFromRounded((float) ($data['amountPaid'] ?? 0)),
                currency: $data['currencyCode'] ?? 'NGN',
                providerId: isset($data['transactionReference']) ? (string) $data['transactionReference'] : null,
                paidAt: $data['paidOn'] ?? null,
                raw: $data,
            ),
            'PENDING', 'PARTIALLY_PAID' => VerificationResult::pending('This payment has not completed yet.', $data),
            default => VerificationResult::failed('This payment was not successful.', $data),
        };
    }

    public function refund(RefundRequest $refund, GatewayCredentials $credentials): RefundResult
    {
        if ($refund->providerId === null) {
            return RefundResult::failed('Monnify refunds need the transaction reference, which this payment has no record of.');
        }

        $response = $this->authenticated($credentials)
            ->post($this->baseUrl($credentials).'/api/v1/refunds/initiate-refund', [
                'transactionReference' => $refund->providerId,
                'refundReference' => $refund->reference,
                'refundAmount' => Naira::floatFromKobo($refund->amountMinor),
                'refundReason' => $refund->reason ?: 'Customer request',
            ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['requestSuccessful'] ?? false) !== true) {
            return RefundResult::failed($body['responseMessage'] ?? 'Monnify refused this refund.', $body);
        }

        return RefundResult::ok(
            refundId: $body['responseBody']['refundReference'] ?? null,
            status: $body['responseBody']['refundStatus'] ?? null,
            raw: $body['responseBody'] ?? [],
        );
    }

    public function parseWebhook(WebhookRequest $request, GatewayCredentials $credentials): ?WebhookEvent
    {
        $signature = (string) $request->header('monnify-signature', '');

        if ($signature === '') {
            return null;
        }

        $expected = hash_hmac('sha512', $request->payload(), (string) $credentials->get('secret_key'));

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $payload = $request->json();
        // Monnify calls the payload `eventData`, not `data`.
        $data = $payload['eventData'] ?? [];

        return new WebhookEvent(
            type: match ($payload['eventType'] ?? null) {
                'SUCCESSFUL_TRANSACTION' => WebhookEventType::PAYMENT_SUCCESS,
                'FAILED_TRANSACTION' => WebhookEventType::PAYMENT_FAILED,
                'SUCCESSFUL_REFUND' => WebhookEventType::REFUND,
                default => WebhookEventType::IGNORED,
            },
            reference: isset($data['paymentReference']) ? (string) $data['paymentReference'] : null,
            providerId: isset($data['transactionReference']) ? (string) $data['transactionReference'] : null,
            amountMinor: isset($data['amountPaid']) ? Naira::koboFromRounded((float) $data['amountPaid']) : null,
            currency: $data['currency'] ?? null,
            paidAt: $data['paidOn'] ?? null,
            raw: $data,
        );
    }

    public function testConnection(GatewayCredentials $credentials): ConnectionResult
    {
        try {
            if ($this->accessToken($credentials) === null) {
                return ConnectionResult::failed('Monnify rejected these keys, or the contract code does not match them.');
            }

            return ConnectionResult::ok('Connected to Monnify.');
        } catch (\Throwable $e) {
            Log::warning('monnify.test_failed', ['error' => $e->getMessage()]);

            return ConnectionResult::failed('Could not reach Monnify.');
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
     * A client already carrying a valid bearer token.
     */
    private function authenticated(GatewayCredentials $credentials): PendingRequest
    {
        $token = $this->accessToken($credentials);

        if ($token === null) {
            // Deliberately an empty token rather than an exception: the call
            // then 401s and the caller reports a connection problem, which is
            // the truthful outcome.
            $token = '';
        }

        return Http::withToken($token)->timeout(30)->acceptJson();
    }

    /**
     * The access token, cached across requests.
     *
     * The cache key hashes the public identifiers only — never the secret in
     * clear, because cache keys surface in dashboards and debug output. The
     * token itself lives only in the cache, never on this object.
     */
    private function accessToken(GatewayCredentials $credentials): ?string
    {
        $apiKey = (string) $credentials->get('api_key');
        $secret = (string) $credentials->get('secret_key');

        if ($apiKey === '' || $secret === '') {
            return null;
        }

        $cacheKey = 'monnify.token.'.hash('sha256', $apiKey.':'.$secret);

        return Cache::remember($cacheKey, self::TOKEN_TTL_SECONDS, function () use ($credentials, $apiKey, $secret): ?string {
            $response = Http::withBasicAuth($apiKey, $secret)
                ->timeout(30)
                ->acceptJson()
                ->post($this->baseUrl($credentials).'/api/v1/auth/login');

            $body = $response->json() ?? [];

            if (! $response->successful() || ($body['requestSuccessful'] ?? false) !== true) {
                Log::warning('monnify.auth_failed', ['status' => $response->status()]);

                return null;
            }

            return $body['responseBody']['accessToken'] ?? null;
        });
    }

    /**
     * Test and live are separate hosts, and the key prefix says which is which.
     */
    private function baseUrl(GatewayCredentials $credentials): string
    {
        return str_starts_with((string) $credentials->get('api_key'), 'MK_TEST')
            ? self::SANDBOX_URL
            : self::LIVE_URL;
    }
}
