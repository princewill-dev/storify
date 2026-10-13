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
use App\Services\PaystackService;
use Illuminate\Support\Facades\Http;

/**
 * Paystack, expressed through the driver contract.
 *
 * This is the first driver, and it deliberately wraps the existing
 * {@see PaystackService} rather than replacing it — that service is correct and
 * battle-tested, and rewriting it in the same change that introduces the
 * abstraction would make a regression impossible to attribute.
 *
 * What it does fix is the statefulness. `PaystackService::usingGateway()`
 * mutates the instance it is called on, so a client injected into a long-lived
 * service would carry the previous business's keys into the next call. Building
 * a fresh client per call removes that: there is no instance to leak through.
 */
final class PaystackGateway implements PaymentGateway
{
    private const BASE_URL = 'https://api.paystack.co';

    public function code(): string
    {
        return 'paystack';
    }

    public function currency(): string
    {
        return 'NGN';
    }

    /**
     * Start a charge.
     *
     * ## There is no split any more, and that is the point
     *
     * This used to send `subaccount`, `transaction_charge` and `bearer`, and to
     * refuse before the request left whenever a declared subaccount did not match
     * the one the credentials carried. The guard existed because Paystack answers
     * a `transaction/initialize` carrying a dropped split perfectly happily: it
     * charges the platform's own account, pays the business nothing, reports
     * success, and says nothing — so the failure surfaced as a business that was
     * never paid.
     *
     * The whole arrangement is gone. Every charge this driver makes now *is* the
     * platform's own account, the gross lands in Storify's balance, and the
     * business is paid from its wallet. A split is not merely unnecessary; naming
     * a subaccount here would settle the business directly while the wallet
     * credited it as though Storify held the money — paying it twice, once by the
     * provider and once by the payout sweep.
     *
     * So the parameters are not written at all rather than written as null, and
     * the class of bug the guard covered cannot be constructed.
     */
    public function initialize(PaymentIntent $intent, GatewayCredentials $credentials): InitializationResult
    {
        $result = $this->client($credentials)->initializePayment(array_filter([
            'email' => $intent->email,
            'amount' => $intent->amountMinor,
            'currency' => $intent->currency,
            'reference' => $intent->reference,
            'callback_url' => $intent->callbackUrl,
            'metadata' => $intent->metadata,
        ], fn ($value) => $value !== null && $value !== ''));

        if (! ($result['success'] ?? false)) {
            return InitializationResult::failed($result['message'] ?? 'Paystack could not start this payment.');
        }

        $data = $result['data'] ?? [];

        return InitializationResult::redirect(
            redirectUrl: (string) ($data['authorization_url'] ?? ''),
            providerReference: isset($data['reference']) ? (string) $data['reference'] : null,
            providerId: isset($data['access_code']) ? (string) $data['access_code'] : null,
            raw: $data,
        );
    }

    public function verify(string $reference, GatewayCredentials $credentials): VerificationResult
    {
        // Uses the double check rather than the single verify: it confirms the
        // transaction through two endpoints and refuses when they disagree.
        // That is the behaviour storefront checkout already depends on, and
        // nothing about introducing an abstraction should quietly loosen it.
        $result = $this->client($credentials)->doubleVerifyPayment($reference);
        $data = $result['data'] ?? [];

        if (! ($result['success'] ?? false)) {
            return VerificationResult::failed($result['message'] ?? 'Paystack could not verify this payment.', $data);
        }

        return match ($data['status'] ?? null) {
            'success' => VerificationResult::paid(
                amountMinor: (int) ($data['amount'] ?? 0),
                currency: $data['currency'] ?? 'NGN',
                providerId: isset($data['id']) ? (string) $data['id'] : null,
                feesMinor: isset($data['fees']) ? (int) $data['fees'] : null,
                paidAt: $data['paid_at'] ?? null,
                raw: $data,
            ),
            // Paystack reports an abandoned or still-open charge as its own
            // status. Treating either as FAILED would reject a payment the
            // customer may yet complete.
            'abandoned', 'ongoing', 'pending', 'processing' => VerificationResult::pending('This payment has not completed yet.', $data),
            default => VerificationResult::failed('This payment was not successful.', $data),
        };
    }

    public function refund(RefundRequest $refund, GatewayCredentials $credentials): RefundResult
    {
        // Implemented here rather than on PaystackService: that class is legacy
        // being wrapped, and it should not grow a method only the driver needs.
        $response = Http::withToken((string) $credentials->get('secret_key'))
            ->timeout(20)
            ->post(self::BASE_URL.'/refund', array_filter([
                'transaction' => $refund->providerId ?? $refund->reference,
                'amount' => $refund->amountMinor,
                'merchant_note' => $refund->reason,
            ]));

        $body = $response->json() ?? [];

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            return RefundResult::failed($body['message'] ?? 'Paystack refused this refund.', $body);
        }

        return RefundResult::ok(
            refundId: isset($body['data']['id']) ? (string) $body['data']['id'] : null,
            status: $body['data']['status'] ?? null,
            raw: $body['data'] ?? [],
        );
    }

    public function parseWebhook(WebhookRequest $request, GatewayCredentials $credentials): ?WebhookEvent
    {
        $signature = (string) $request->header('x-paystack-signature', '');

        if ($signature === '') {
            return null;
        }

        $secret = (string) $credentials->get('secret_key');
        $expected = hash_hmac('sha512', $request->payload(), $secret);

        // hash_equals, never ===: a timing-safe comparison is the whole point of
        // checking a signature rather than merely matching it.
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $payload = $request->json();
        $data = $payload['data'] ?? [];

        $type = match ($payload['event'] ?? null) {
            'charge.success' => WebhookEventType::PAYMENT_SUCCESS,
            'charge.failed' => WebhookEventType::PAYMENT_FAILED,
            'refund.processed' => WebhookEventType::REFUND,
            // `transfer.*` is a payout, not a charge: the reference in its data is
            // the payout's, and its status is the transfer's own (`success`,
            // `failed`, `reversed`, `otp`). Without these three the most important
            // one — a transfer reversed after it landed — was parsed as an event
            // nothing acts on, and the payout sweep would never look at the row
            // again, because it only ever re-reads rows it thinks are still moving.
            //
            // `transferrecipient.create` deliberately is not matched: it is about
            // a destination being registered, which is a step of a send rather than
            // an outcome of one.
            'transfer.success', 'transfer.failed', 'transfer.reversed', 'transfer.reversal' => WebhookEventType::PAYOUT,
            default => WebhookEventType::IGNORED,
        };

        return new WebhookEvent(
            type: $type,
            reference: isset($data['reference']) ? (string) $data['reference'] : null,
            providerId: isset($data['id']) ? (string) $data['id'] : null,
            amountMinor: isset($data['amount']) ? (int) $data['amount'] : null,
            currency: $data['currency'] ?? null,
            feesMinor: isset($data['fees']) ? (int) $data['fees'] : null,
            paidAt: $data['paid_at'] ?? null,
            raw: $data,
        );
    }

    public function testConnection(GatewayCredentials $credentials): ConnectionResult
    {
        $result = (new PaystackService)->testApiKeys(
            (string) $credentials->get('secret_key'),
            $credentials->get('public_key'),
        );

        return ($result['success'] ?? false)
            ? ConnectionResult::ok($result['message'] ?? 'Connection successful.')
            : ConnectionResult::failed($result['message'] ?? 'Paystack rejected these keys.', $result);
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
     * A fresh, credential-bearing client for this call only.
     *
     * Not a cached instance and not a mutation of a shared one — that is the
     * whole reason the driver exists.
     */
    private function client(GatewayCredentials $credentials): PaystackService
    {
        return (new PaystackService)->usingGateway($credentials->toGatewayObject());
    }
}
