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
 * Flutterwave.
 *
 * Amounts go over the wire in the major unit (naira), unlike Paystack which
 * takes kobo — a difference that is silent and expensive if it is missed, so
 * every amount here goes through `Naira::floatFromKobo` from the kobo the
 * intent carries rather than being multiplied at the call site.
 *
 * Its webhook is also the odd one out: instead of an HMAC it echoes back a
 * "secret hash" the merchant typed into the dashboard, so verification is a
 * constant-time string comparison rather than a signature.
 */
final class FlutterwaveGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.flutterwave.com/v3';

    public function code(): string
    {
        return 'flutterwave';
    }

    public function currency(): string
    {
        return 'NGN';
    }

    public function initialize(PaymentIntent $intent, GatewayCredentials $credentials): InitializationResult
    {
        $response = $this->client($credentials)->post(self::BASE_URL.'/payments', [
            'tx_ref' => $intent->reference,
            'amount' => Naira::floatFromKobo($intent->amountMinor),
            'currency' => $intent->currency,
            'redirect_url' => $intent->callbackUrl,
            'customer' => array_filter([
                'email' => $intent->email,
                'name' => $intent->customerName,
            ]),
            'customizations' => ['title' => $intent->description ?: 'Order payment'],
        ]);

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['status'] ?? null) !== 'success') {
            return InitializationResult::failed($body['message'] ?? 'Flutterwave could not start this payment.', $body);
        }

        return InitializationResult::redirect(
            redirectUrl: (string) ($body['data']['link'] ?? ''),
            providerReference: $intent->reference,
            raw: $body['data'] ?? [],
        );
    }

    public function verify(string $reference, GatewayCredentials $credentials): VerificationResult
    {
        $response = $this->client($credentials)
            ->get(self::BASE_URL.'/transactions/verify_by_reference', ['tx_ref' => $reference]);

        $body = $response->json() ?? [];
        $data = $body['data'] ?? [];

        if (! $response->successful() || ($body['status'] ?? null) !== 'success') {
            return VerificationResult::failed($body['message'] ?? 'Flutterwave could not verify this payment.', $body);
        }

        return match (strtolower((string) ($data['status'] ?? ''))) {
            'successful' => VerificationResult::paid(
                amountMinor: Naira::koboFromRounded((float) ($data['amount'] ?? 0)),
                currency: $data['currency'] ?? 'NGN',
                providerId: isset($data['id']) ? (string) $data['id'] : null,
                feesMinor: isset($data['app_fee']) ? Naira::koboFromRounded((float) $data['app_fee']) : null,
                paidAt: $data['created_at'] ?? null,
                raw: $data,
            ),
            'pending' => VerificationResult::pending('This payment has not completed yet.', $data),
            default => VerificationResult::failed('This payment was not successful.', $data),
        };
    }

    public function refund(RefundRequest $refund, GatewayCredentials $credentials): RefundResult
    {
        if ($refund->providerId === null) {
            return RefundResult::failed('Flutterwave refunds need the transaction id, which this payment has no record of.');
        }

        $response = $this->client($credentials)->post(
            self::BASE_URL.'/transactions/'.$refund->providerId.'/refund',
            ['amount' => Naira::floatFromKobo($refund->amountMinor)],
        );

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['status'] ?? null) !== 'success') {
            return RefundResult::failed($body['message'] ?? 'Flutterwave refused this refund.', $body);
        }

        return RefundResult::ok(
            refundId: isset($body['data']['id']) ? (string) $body['data']['id'] : null,
            status: $body['data']['status'] ?? null,
            raw: $body['data'] ?? [],
        );
    }

    public function parseWebhook(WebhookRequest $request, GatewayCredentials $credentials): ?WebhookEvent
    {
        $provided = (string) $request->header('verif-hash', '');
        $expected = (string) $credentials->get('webhook_secret', '');

        // Flutterwave does not sign the body — it echoes the secret hash the
        // merchant configured. A plain comparison, but still constant-time.
        if ($provided === '' || $expected === '' || ! hash_equals($expected, $provided)) {
            return null;
        }

        $payload = $request->json();
        $data = $payload['data'] ?? [];

        return new WebhookEvent(
            type: match ($payload['event'] ?? null) {
                'charge.completed' => $this->isSuccessful($data) ? WebhookEventType::PAYMENT_SUCCESS : WebhookEventType::PAYMENT_FAILED,
                default => WebhookEventType::IGNORED,
            },
            reference: isset($data['tx_ref']) ? (string) $data['tx_ref'] : null,
            providerId: isset($data['id']) ? (string) $data['id'] : null,
            amountMinor: isset($data['amount']) ? Naira::koboFromRounded((float) $data['amount']) : null,
            currency: $data['currency'] ?? null,
            feesMinor: isset($data['app_fee']) ? Naira::koboFromRounded((float) $data['app_fee']) : null,
            paidAt: $data['created_at'] ?? null,
            raw: $data,
        );
    }

    public function testConnection(GatewayCredentials $credentials): ConnectionResult
    {
        try {
            // A single-page transaction list: the lightest call that proves the
            // secret key is accepted.
            $response = $this->client($credentials)->get(self::BASE_URL.'/transactions', ['per_page' => 1]);

            if ($response->status() === 401) {
                return ConnectionResult::failed('Flutterwave rejected this secret key.');
            }

            return $response->successful()
                ? ConnectionResult::ok('Connected to Flutterwave.')
                : ConnectionResult::failed('Flutterwave answered with '.$response->status().'.');
        } catch (\Throwable $e) {
            Log::warning('flutterwave.test_failed', ['error' => $e->getMessage()]);

            return ConnectionResult::failed('Could not reach Flutterwave.');
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
     * @param  array<string, mixed>  $data
     */
    private function isSuccessful(array $data): bool
    {
        return strtolower((string) ($data['status'] ?? '')) === 'successful';
    }

    private function client(GatewayCredentials $credentials): PendingRequest
    {
        return Http::withToken((string) $credentials->get('secret_key'))
            ->timeout(30)
            ->acceptJson();
    }
}
