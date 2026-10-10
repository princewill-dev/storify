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
 * Bitfra — non-custodial crypto.
 *
 * Two things make it unlike the others here, and both are in the contract
 * rather than in this class:
 *
 * 1. **It prices in USD.** The currency gate in the resolver keeps it away from
 *    NGN stores, so it is only ever offered where the store charges dollars.
 * 2. **It identifies a payment by its own `payment_id`, not by a reference we
 *    supply.** `POST /payments` takes an amount and an email and returns a
 *    link; there is no field for our reference. So a webhook carries Bitfra's
 *    id and nothing of ours, and the settlement processor falls back to looking
 *    a transaction up by provider id when the reference does not match.
 *
 * `verify()` is therefore addressed by Bitfra's id, not by ours — the string it
 * is handed has to be the `payment_id`. Everything that calls it either has
 * that id from the provider's own payload (a webhook quotes it) or is given it
 * alongside our reference.
 *
 * Confirmation is a threshold, not a receipt: Bitfra reports `payment.paid`
 * when a transfer is seen and `payment.completed` once it has enough
 * confirmations. **Only `payment.completed` settles** — see `parseWebhook()`,
 * which is where that distinction is enforced, and `verify()`, which does not
 * try to re-derive it from the API's own status vocabulary.
 */
final class BitfraGateway implements PaymentGateway
{
    private const LIVE_URL = 'https://bitfra.net/api/v1';

    private const SANDBOX_URL = 'https://sandbox.bitfra.net/api/v1';

    /** Reject a webhook whose timestamp is older than this, to blunt replays. */
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    /**
     * The statuses `GET /payments/{payment_id}` can report for money that is
     * really there. See `verify()` for why both count.
     */
    private const SETTLED_STATUSES = ['PAID', 'COMPLETED'];

    public function code(): string
    {
        return 'bitfra';
    }

    public function currency(): string
    {
        return 'USD';
    }

    public function initialize(PaymentIntent $intent, GatewayCredentials $credentials): InitializationResult
    {
        $response = $this->client($credentials)->post($this->baseUrl($credentials).'/payments', [
            // Bitfra takes USD as a decimal number, and the intent carries
            // minor units — cents for a USD store. Divided here rather than
            // with the naira helper, because the units are not naira.
            'amount' => round($intent->amountMinor / 100, 2),
            'email' => $intent->email,
            'return_url' => $intent->callbackUrl,
            'note' => $intent->description,
        ]);

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            return InitializationResult::failed($body['message'] ?? 'Bitfra could not start this payment.', $body);
        }

        return InitializationResult::redirect(
            redirectUrl: (string) ($body['payment_link'] ?? ''),
            // Bitfra's own id. It is what its webhook will quote, and what the
            // processor falls back to matching on.
            providerReference: isset($body['payment_id']) ? (string) $body['payment_id'] : null,
            providerId: isset($body['payment_id']) ? (string) $body['payment_id'] : null,
            raw: $body,
        );
    }

    /**
     * `GET /payments/{payment_id}`.
     *
     * This returned PENDING unconditionally, which quietly made every Bitfra
     * sale uncompletable: the settlement processor re-verifies with the
     * provider before it will touch a transaction, so a signed
     * `payment.completed` webhook was parsed correctly, matched to its
     * transaction, and then thrown away as "verification failed". The storefront
     * and the till ask the same question through the same method, so they were
     * dead for this provider too.
     *
     * The API does not document the full set of statuses it can return, only
     * `PENDING`, `PAID` and `CANCELLED`. `PAID` is what its own example shows
     * for a payment that carries a `tx_id`, i.e. an on-chain transfer that has
     * been seen. Both it and `COMPLETED` are therefore treated as settled here,
     * and that is not a loosening of the confirmation threshold: the threshold
     * is enforced upstream in `parseWebhook()`, which settles only on
     * `payment.completed` and maps `payment.paid` to IGNORED. The webhook
     * decides *when* a payment counts; this decides whether the money is really
     * there and how much of it, which is the same division of labour the
     * processor applies to every other provider.
     */
    public function verify(string $reference, GatewayCredentials $credentials): VerificationResult
    {
        $response = $this->client($credentials)->get(
            $this->baseUrl($credentials).'/payments/'.rawurlencode($reference)
        );

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            // A 404 is the ordinary answer for an id Bitfra does not know. That
            // happens when the caller only had our own reference — the id is the
            // provider's, and there is no lookup by ours — and it is also what a
            // payment created a moment ago can look like. Neither is a refusal,
            // so it reads as pending rather than failed: a failed result is
            // terminal at the till, and an id we cannot ask about yet must not
            // abort a sale that is going to be paid.
            if ($response->status() === 404) {
                return VerificationResult::pending('Bitfra has not seen this payment yet.', $body);
            }

            return VerificationResult::failed($body['message'] ?? 'Bitfra could not verify this payment.', $body);
        }

        $status = strtoupper((string) ($body['status'] ?? ''));

        if (! in_array($status, self::SETTLED_STATUSES, true)) {
            return match ($status) {
                'CANCELLED', 'EXPIRED', 'FAILED' => VerificationResult::failed(
                    'This crypto payment is no longer open.',
                    $body,
                ),
                default => VerificationResult::pending(
                    'Crypto payments are confirmed on-chain. Bitfra has not reported the transfer yet.',
                    $body,
                ),
            };
        }

        // USD, as a decimal number — the inverse of what `initialize()` sends,
        // and deliberately not the naira helper, because the units are not
        // naira.
        $usd = $body['amount_usd'] ?? $body['amount'] ?? null;

        if ($usd === null) {
            // Paid but priceless: a confirmation for an unknown sum is not
            // something to settle an order on. The till compares the amount it
            // is given against the amount it asked for, and a missing one
            // already reads as zero there — this just says so plainly instead.
            return VerificationResult::pending('Bitfra confirmed this payment without reporting an amount.', $body);
        }

        return VerificationResult::paid(
            amountMinor: (int) round(((float) $usd) * 100),
            currency: 'USD',
            providerId: isset($body['payment_id']) ? (string) $body['payment_id'] : $reference,
            paidAt: isset($body['updated_at']) ? (string) $body['updated_at'] : null,
            raw: $body,
        );
    }

    public function refund(RefundRequest $refund, GatewayCredentials $credentials): RefundResult
    {
        return RefundResult::failed('Crypto payments are non-custodial and cannot be reversed by the platform.');
    }

    public function parseWebhook(WebhookRequest $request, GatewayCredentials $credentials): ?WebhookEvent
    {
        $header = (string) $request->header('x-bixmerchant-signature', '');

        if ($header === '') {
            return null;
        }

        $timestamp = $this->timestampFrom($header);
        $signature = $this->signatureFrom($header);

        if ($timestamp === null || $signature === null) {
            return null;
        }

        // A signed request is only trustworthy while it is fresh; without this
        // a captured webhook could be replayed indefinitely.
        if (abs(time() - $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            Log::warning('bitfra.webhook.stale_signature', ['timestamp' => $timestamp]);

            return null;
        }

        $expected = hash_hmac(
            'sha256',
            $timestamp.'.'.$request->payload(),
            (string) $credentials->get('webhook_secret'),
        );

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $payload = $request->json();

        return new WebhookEvent(
            type: match ($payload['event'] ?? null) {
                // Only `completed` clears the confirmation threshold. `paid`
                // means a transfer was seen, which is not yet settled money.
                'payment.completed' => WebhookEventType::PAYMENT_SUCCESS,
                'payment.expired', 'payment.cancelled' => WebhookEventType::PAYMENT_FAILED,
                default => WebhookEventType::IGNORED,
            },
            // Bitfra's id — see the class docblock for why the processor can
            // still match it to our transaction.
            reference: isset($payload['payment_id']) ? (string) $payload['payment_id'] : null,
            providerId: isset($payload['payment_id']) ? (string) $payload['payment_id'] : null,
            amountMinor: isset($payload['amount_usd'])
                ? (int) round(((float) $payload['amount_usd']) * 100)
                : null,
            currency: 'USD',
            raw: $payload,
        );
    }

    public function testConnection(GatewayCredentials $credentials): ConnectionResult
    {
        try {
            $response = $this->client($credentials)->get($this->baseUrl($credentials).'/store');

            if ($response->status() === 401) {
                return ConnectionResult::failed('Bitfra rejected this API key.');
            }

            if ($response->status() === 403) {
                return ConnectionResult::failed('Bitfra says this store is disabled.');
            }

            return $response->successful()
                ? ConnectionResult::ok('Connected to Bitfra.')
                : ConnectionResult::failed('Bitfra answered with '.$response->status().'.');
        } catch (\Throwable $e) {
            Log::warning('bitfra.test_failed', ['error' => $e->getMessage()]);

            return ConnectionResult::failed('Could not reach Bitfra.');
        }
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function supportsPartialPayment(): bool
    {
        return false;
    }

    /**
     * `t=<unix timestamp>,v1=<hmac>`.
     */
    private function timestampFrom(string $header): ?int
    {
        if (preg_match('/t=(\d+)/', $header, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function signatureFrom(string $header): ?string
    {
        if (preg_match('/v1=([a-f0-9]+)/i', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /** Test keys are not interchangeable with live ones, and the hosts differ. */
    private function baseUrl(GatewayCredentials $credentials): string
    {
        $key = (string) $credentials->get('api_key');

        return str_contains($key, 'sandbox') ? self::SANDBOX_URL : self::LIVE_URL;
    }

    private function client(GatewayCredentials $credentials): PendingRequest
    {
        return Http::withHeaders(['X-API-Key' => (string) $credentials->get('api_key')])
            ->timeout(30)
            ->acceptJson();
    }
}
