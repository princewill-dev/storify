<?php

namespace App\Http\Controllers\Payment;

use App\Enums\WebhookEventType;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Payments\Data\WebhookRequest;
use App\Services\Subscription\PlatformBillingGateway;
use App\Services\Subscription\SubscriptionPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a gateway tells Storify that one of *its own* subscriptions was paid.
 *
 * Separate from {@see PaymentWebhookController}, and not a scope of it. That
 * endpoint settles a store's orders against a business's own connection, and
 * has to search the connections table for the one whose secret verifies the
 * signature. This one settles the platform's own charges against credentials the
 * platform holds in `.env`, so there is exactly one candidate and nothing to
 * search: the provider in the path either is the gateway an admin selected or
 * the delivery is refused.
 *
 * It exists because activation used to depend entirely on the customer's browser
 * making it back to `GET /management/subscription/callback`. A customer who paid
 * and then closed the tab had paid — the money was with the provider — and was
 * left unsubscribed until somebody noticed. The callback is still the fast path;
 * this is the one that makes it not the only one.
 */
final class SubscriptionWebhookController extends Controller
{
    public function __construct(
        private readonly PlatformBillingGateway $billing,
        private readonly SubscriptionPaymentService $payments,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        // Two conditions, both required, and the same predicate the admin screen
        // reports from: this provider is the gateway the platform bills through,
        // and its webhook secret is configured. A delivery from a gateway we no
        // longer bill through is about payments this platform is not reconciling,
        // and acting on it could activate a subscription nothing was paid for.
        if (! $this->billing->handlesWebhooksFor($provider)) {
            Log::warning('api.webhook.subscription_unknown_provider', [
                'provider' => $provider,
                'billing_gateway' => $this->billing->code(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 'unknown provider'], Response::HTTP_NOT_FOUND);
        }

        $driver = $this->billing->driverFor($provider);

        // Unreachable — `handlesWebhooksFor()` is false for a code with no
        // driver — but the alternative is a null dereference if that ever
        // changes, and it costs one branch to not have to be sure.
        if ($driver === null) {
            return response()->json(['status' => 'unknown provider'], Response::HTTP_NOT_FOUND);
        }

        $event = $driver->parseWebhook(
            new WebhookRequest($request->getContent(), $this->headers($request)),
            $this->billing->credentialsFor($provider),
        );

        if ($event === null) {
            Log::warning('api.webhook.subscription_unverified_signature', [
                'provider' => $provider,
                'ip' => $request->ip(),
            ]);

            // 400, unlike the merchant endpoint's 401, and deliberately: the
            // signature is over this exact body with this exact secret, so it
            // will not verify on a retry either. A 4xx is a delivery the provider
            // should not spend its retry budget on, which is the point of
            // answering at all rather than staying silent.
            return response()->json(['status' => 'unverified'], Response::HTTP_BAD_REQUEST);
        }

        if ($event->type === WebhookEventType::IGNORED) {
            // Verified and understood, but not an event this endpoint acts on.
            // Answered 200 so the provider stops retrying it.
            return response()->json(['status' => 'unhandled event']);
        }

        if (! $event->isSettlement()) {
            return response()->json(['status' => 'noted']);
        }

        return $this->settle($provider, $event->reference);
    }

    /**
     * Confirm the payment with the provider and activate the subscription.
     *
     * The payment is re-verified with the provider rather than trusted from the
     * body: a valid signature proves the delivery came from the provider, not
     * that the amount in it is the amount we asked for.
     */
    private function settle(string $provider, ?string $reference): JsonResponse
    {
        $payment = $reference === null ? null : $this->findPayment($reference);

        if ($payment === null) {
            // 200, not 404, for the reason the merchant processor gives: the
            // delivery may simply have arrived before the row was written, and an
            // error makes the provider retry something that is not wrong. A
            // reference that never matches anything is still worth a log line.
            Log::warning('api.webhook.subscription_payment_not_found', [
                'provider' => $provider,
                'reference' => $reference,
            ]);

            return response()->json(['status' => 'payment not found']);
        }

        // The idempotency guard, read before any work: a replayed delivery is
        // the ordinary case for a webhook, not an attack.
        if ($payment->status === Payment::STATUS_SUCCESS) {
            return response()->json(['status' => 'already processed']);
        }

        // `inspect`, not `verify`: a disagreement here must not fail the payment.
        // The customer may still be in the checkout, and this delivery may be
        // one of several — see the method's own note. `activate()` below is
        // guarded on the status inside a locked row, so a second delivery that
        // gets past this read still cannot activate twice.
        $verification = $this->payments->inspectGatewayPayment($payment);

        if (! $verification->verified) {
            Log::warning('api.webhook.subscription_verification_failed', [
                'provider' => $provider,
                'payment_id' => $payment->id,
                'expected_kobo' => $verification->expectedKobo,
                'verified_kobo' => $verification->verifiedKobo,
                'verified_currency' => $verification->verifiedCurrency,
            ]);

            return response()->json(['status' => 'verification failed']);
        }

        // No requesting user: a webhook has no session. `activate()` resolves the
        // payer from the payment itself, which is what the notifications and the
        // ledger attribution follow anyway.
        $activated = $this->payments->activate($payment, $verification, null);

        return response()->json(['status' => $activated ? 'success' : 'already processed']);
    }

    /**
     * The payment a provider's reference names.
     *
     * Three names for one payment, tried in order of how much they can be
     * trusted. `reference` is ours and unique platform-wide, so a hit there is
     * unambiguous. `gateway_reference` is the provider's own id, written when the
     * payment settled — which means this branch serves a *replayed* delivery
     * after a successful one, not the first. `metadata.provider_reference` is the
     * name a provider that did not adopt our reference issued for the payment at
     * initialize — Bachs' `checkout_id`, which is what its deliveries quote — and
     * is the only one that matches a Bachs delivery at all.
     *
     * No business filter: a webhook body says nothing about whose payment it is,
     * and it does not need to, because none of these names is guessable.
     */
    private function findPayment(string $reference): ?Payment
    {
        return Payment::query()->where('reference', $reference)->first()
            ?? Payment::query()->where('gateway_reference', $reference)->first()
            ?? Payment::query()->where('metadata->provider_reference', $reference)->first();
    }

    /**
     * Headers with lower-cased names, because providers disagree on casing —
     * `X-Bachs-Signature-V2` and `x-bachs-signature-v2` both occur.
     *
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        return $headers;
    }
}
