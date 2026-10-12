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
use Illuminate\Support\Facades\Log;

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
     * Start a charge, splitting it when the caller asked for a subaccount.
     *
     * ## The guard is the point
     *
     * The three split fields are only written when a subaccount was declared,
     * and a declared subaccount that is missing, malformed, or not the one these
     * credentials name is **refused before the request leaves**. Paystack answers
     * a `transaction/initialize` with no `subaccount` perfectly happily: it
     * charges the platform's own account, pays the business nothing, and reports
     * success. Nothing in the response says a split was dropped, so the failure
     * is discovered by a business that never got paid — which is why this is the
     * one place in the driver that refuses rather than best-efforts.
     *
     * It mirrors {@see BachsGateway::directChargeProblem()}: only ever refuses a
     * charge the caller *asked* to be direct, so an ordinary charge — the
     * platform's own billing, or a business's own keys — declares no account and
     * is untouched.
     *
     * ## What each field means
     *
     * `subaccount` is the split: Paystack credits that subaccount's settlement
     * bank and the remainder to this account, at settlement.
     *
     * `transaction_charge` is the platform's cut in kobo and it *overrides* the
     * subaccount's `percentage_charge` for this charge — which is why the
     * authoritative number is sent per transaction rather than left to the
     * subaccount's default. It is the same figure the Bachs path sends as
     * `platform_fee`, from the same `PlatformCommission::on()`.
     *
     * `bearer: subaccount` makes the business bear Paystack's own processing fee,
     * so the platform's cut is what it says it is rather than depending on how
     * the fee was apportioned. It is set only alongside a split, because `bearer`
     * without a `subaccount` asks for something that does not exist.
     */
    public function initialize(PaymentIntent $intent, GatewayCredentials $credentials): InitializationResult
    {
        if (($problem = $this->subaccountProblem($intent, $credentials)) !== null) {
            Log::error('paystack.split_charge.refused', [
                'reference' => $intent->reference,
                'reason' => $problem,
                'declared_subaccount' => $intent->directChargeAccountId,
                'credential_account' => $credentials->accountId(),
            ]);

            return InitializationResult::failed($problem);
        }

        $subaccount = $intent->directChargeAccountId;

        $result = $this->client($credentials)->initializePayment(array_filter([
            'email' => $intent->email,
            'amount' => $intent->amountMinor,
            'currency' => $intent->currency,
            'reference' => $intent->reference,
            'callback_url' => $intent->callbackUrl,
            'metadata' => $intent->metadata,
            'subaccount' => $subaccount,
            // Kobo, as Paystack documents it, and omitted rather than zeroed when
            // the platform takes no cut: a `0` would be a split instruction, and
            // the subaccount's percentage_charge is the better fallback then.
            'transaction_charge' => $this->platformChargeMinor($intent),
            'bearer' => $subaccount === null ? null : 'subaccount',
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
     * Why this split charge must not be posted, or null when it may be.
     *
     * Only ever refuses a charge that declared a subaccount. The two ids are
     * compared rather than trusted, exactly as they are on the Bachs path: a
     * mismatch means the resolver answered for one subaccount and the credentials
     * name another, and the only safe response is to stop rather than to send
     * money to whichever account happens to be on the credentials.
     *
     * The prefix check is Paystack's own naming — a subaccount code starts
     * `ACCT_` — and it is here for the same reason Bachs' `acct_` check is: a
     * value that is obviously not a subaccount code is a caller bug, and letting
     * Paystack answer it turns a bug into a charge on the platform's account.
     */
    private function subaccountProblem(PaymentIntent $intent, GatewayCredentials $credentials): ?string
    {
        $declared = $intent->directChargeAccountId;

        if ($declared === null || $declared === '') {
            return null;
        }

        if (! str_starts_with(strtoupper($declared), 'ACCT_')) {
            return 'This payment could not be started: the settlement account it needs is not a valid subaccount code.';
        }

        if ($credentials->accountId() !== $declared) {
            return 'This payment could not be started: the payment account does not match the account being charged.';
        }

        if ((string) $credentials->get('secret_key') === '') {
            return 'This payment could not be started: no gateway credentials are configured for it.';
        }

        return null;
    }

    /**
     * The platform's cut of this charge, in kobo — or null when it takes none.
     *
     * Null rather than zero on purpose: no cut configured is not an instruction
     * to take nothing, it is the absence of a split override, and Paystack then
     * falls back to the subaccount's `percentage_charge`. Sending `0` would be a
     * real instruction that the platform takes nothing, which is a different
     * arrangement from having no commission at all.
     */
    private function platformChargeMinor(PaymentIntent $intent): ?int
    {
        $fee = $intent->platformFeeMinor;

        return $fee === null || $fee < 1 ? null : $fee;
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
