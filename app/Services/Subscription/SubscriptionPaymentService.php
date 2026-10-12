<?php

namespace App\Services\Subscription;

use App\Actions\Subscriptions\ActivateSubscriptionWithCoupon;
use App\Actions\Subscriptions\CouponActivationResult;
use App\Mail\CouponExhaustedMail;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Repositories\Subscription\SubscriptionPaymentRepository;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Payments\Data\InitializationResult;
use App\Services\Payments\Data\PaymentIntent;
use App\Services\StoreActivationNotifier;
use App\Support\Money\Naira;
use App\Support\Payments\CallbackUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The WS-08 money path's atomic workflows: the pending attempt triple, the
 * gateway call with its failure bookkeeping and the verified activation with
 * its post-commit mail, notifications and ledger posting.
 *
 * The gateway is {@see PlatformBillingGateway} rather than Paystack directly, so
 * the platform bills through whichever provider an admin selected. The driver
 * contract is the same one the merchant path uses, which is what keeps this
 * class free of `if ($code === '…')` arms: a provider that can take a merchant's
 * money can take Storify's.
 *
 * One normalisation is load-bearing. Every driver returns its own response body,
 * and three things here read Paystack's keys out of it: the idempotent replay
 * (`gateway_response.authorization_url`) and the repository's `$gateway['id']`
 * for the payment's `gateway_reference`. So `initializeWithGateway()` writes
 * `array_merge($result->raw, ['id' => …, 'authorization_url' => …])` — the
 * provider's real payload, plus the two names this codebase asks for by.
 */
final class SubscriptionPaymentService
{
    public function __construct(
        private readonly PlatformBillingGateway $billing,
        private readonly SubscriptionPaymentRepository $payments,
        private readonly ActivateSubscriptionWithCoupon $couponActivator,
        private readonly StoreActivationNotifier $activationNotifier,
    ) {}

    /**
     * An atomic pending Subscription + Payment + Transaction.
     *
     * The unique (business_id, idempotency_key) index on payments is what
     * lets a concurrent duplicate surface as UniqueConstraintViolationException
     * to the caller rather than as a second charge.
     *
     * @return array{0: Subscription, 1: Payment}
     */
    public function startAttempt(
        User $user,
        SubscriptionPlan $plan,
        ?Coupon $coupon,
        string $idempotencyKey,
        string $paymentType,
        ?string $ipAddress,
        int $planAmountKobo,
        int $discountKobo,
        int $totalKobo,
    ): array {
        return DB::transaction(function () use ($user, $plan, $coupon, $idempotencyKey, $paymentType, $ipAddress, $planAmountKobo, $discountKobo, $totalKobo) {
            $subscription = $this->payments->createPendingSubscription($user, $plan);

            $payment = $this->payments->createPendingPayment($user, $plan, $subscription, [
                'idempotency_key' => $idempotencyKey,
                'amount' => Naira::decimalFromKobo($totalKobo),
                'currency' => $plan->currency,
                'payment_type' => $paymentType,
                'ip_address' => $ipAddress,
                // Recorded from the first write, not at the gateway call: the
                // pending transaction is filed under this code a line below, and
                // a payment that is never initialized still has to say which
                // gateway it was started against.
                'payment_gateway' => $this->billing->code(),
                'metadata' => [
                    'plan_name' => $plan->name,
                    'plan_id' => $plan->id,
                    'plan_interval' => $plan->interval,
                    'coupon_code' => $coupon?->code,
                    'base_amount_kobo' => $planAmountKobo,
                    'discount_kobo' => $discountKobo,
                    'amount_kobo' => $totalKobo,
                ],
            ]);

            $this->payments->createPendingTransaction($payment, $paymentType);

            return [$subscription, $payment];
        });
    }

    /**
     * Send the pending attempt to whichever gateway platform billing is set to.
     * A failed initialization is marked on the payment, subscription and
     * transaction inside one transaction so it cannot sit indefinitely pending.
     */
    public function initializeWithGateway(
        User $user,
        SubscriptionPlan $plan,
        Subscription $subscription,
        Payment $payment,
        int $totalKobo,
        ?string $callbackUrl,
    ): InitializationResult {
        $driver = $this->billing->driver();

        // Refused before the provider is called. An earlier version of this
        // method assumed a gateway that was always there, and the failure mode
        // of guessing — charging through a provider nobody selected — is a
        // customer paying the wrong company.
        if ($driver === null || ! $this->billing->available()) {
            $message = $this->billing->unavailableReason() ?? 'No payment gateway is available for this charge.';
            $this->markInitializationFailed($payment, $subscription, $message);

            return InitializationResult::failed($message);
        }

        $result = $driver->initialize(
            new PaymentIntent(
                reference: $payment->reference,
                amountMinor: $totalKobo,
                // The provider's own currency, not the plan's. They agree for
                // every gateway in the catalogue today; passing the plan's
                // instead would silently send the plan's units to a provider
                // that prices in another.
                currency: $driver->currency(),
                email: (string) $user->email,
                // The reference rides on the return URL, because the callback
                // page has nothing else to name this payment by. Paystack
                // appends one of its own; Bachs appends `checkout_id`, which
                // the callback endpoint has no lookup for — so a paid Bachs
                // subscription ended on "No payment reference was supplied."
                // See {@see CallbackUrl}.
                callbackUrl: CallbackUrl::withReference(
                    $callbackUrl ?? url('/subscription/callback'),
                    $payment->reference,
                ),
                customerName: null,
                metadata: [
                    'user_id' => $user->id,
                    'business_id' => $user->business_id,
                    'payment_id' => $payment->id,
                    'subscription_id' => $subscription->id,
                    'plan_name' => $plan->name,
                ],
            ),
            $this->billing->credentials(),
        );

        if (! $result->success) {
            $this->markInitializationFailed($payment, $subscription, $result->message ?? 'Initialization failed.');

            return $result;
        }

        $this->payments->recordInitializedGateway(
            $payment,
            $driver->code(),
            $this->normalizedGateway($result),
            // For a gateway that names its own payments, this is the only name
            // the payment can be verified under later — the same round-trip the
            // storefront and the till make. Null for the ones that adopt ours,
            // and null is not stored: an absent key means "ask under the
            // reference we issued".
            $result->ownReference($payment->reference),
        );

        return $result;
    }

    /**
     * Verify the payment with the gateway it was made through and fail it —
     * payment and transaction together — when the gateway disagrees on status,
     * underpayment or currency.
     *
     * A provider reporting *more* than was asked for is not a disagreement and
     * does not fail the payment: see {@see self::inspectGatewayPayment()}.
     *
     * `koboFromStrict` is deliberate: a malformed stored amount must convert to
     * zero and fail the comparison rather than match a guessed value.
     *
     * Both sides of the comparison are now the provider's own figures under the
     * driver contract, rather than Paystack's field names. That matters for the
     * gateways that report money as a decimal string (Bachs) or in the major
     * unit: each driver converts to minor units before this ever sees it, so
     * there is one place — the driver — where a provider's units are known.
     */
    public function verifyGatewayPayment(Payment $payment, ?string $providerReference = null): GatewayVerificationResult
    {
        $verification = $this->inspectGatewayPayment($payment, $providerReference);

        if (! $verification->verified) {
            $this->markGatewayMismatch($payment);
        }

        return $verification;
    }

    /**
     * The read half of {@see verifyGatewayPayment()} — ask, compare, report, and
     * change nothing.
     *
     * For callers that must not fail a payment on a disagreement. That is the
     * webhook: a delivery is a notification about a payment the customer may
     * still be completing, and it can be replayed, so a disagreement there is
     * far more likely to be a race than a fact. Failing the payment would burn
     * an attempt the browser callback or the provider's next delivery would have
     * settled. The browser callback keeps the failing behaviour: it is the end
     * of the line, and there is somebody watching to be told.
     */
    public function inspectGatewayPayment(Payment $payment, ?string $providerReference = null): GatewayVerificationResult
    {
        // The gateway the payment was *made through*, not the one selected now.
        // An admin switching platform billing from Paystack to Bachs does not
        // rewrite the payments already in flight, and asking the newly selected
        // provider about them would be asking a stranger about a stranger's
        // reference. The switch stops new checkouts; it does not void old ones.
        $code = $this->chargedThrough($payment);

        $driver = $this->billing->driverFor($code);
        $problem = $this->billing->credentialProblem($code);
        $expectedKobo = Naira::koboFromStrict($payment->amount);

        if ($driver === null || $problem !== null) {
            Log::warning('api.management.subscription_payment_gateway_unusable', [
                'payment_id' => $payment->id,
                'payment_gateway' => $code,
                'reason' => $problem,
            ]);

            return new GatewayVerificationResult(
                false,
                [],
                $expectedKobo,
                -1,
                strtoupper((string) $payment->currency),
            );
        }

        $reference = $providerReference
            ?? data_get($payment->metadata, 'provider_reference')
            ?? $payment->reference;

        $verification = $driver->verify($reference, $this->billing->credentialsFor($code));
        $verifiedKobo = $verification->amountMinor ?? -1;
        $verifiedCurrency = strtoupper((string) $verification->currency);

        // `>=`, not `===`: a provider reporting **more** than was asked for is
        // settled money, and refusing it rejects a customer who has already
        // paid. Bachs is the case that forced this — it grosses the customer's
        // charge up by its own fee, so ₦75,000 asked for arrives as ₦76,125,
        // exactly 1.5% — and the merchant path reached the same conclusion
        // first, in `CheckoutPaymentService::isUnderpaid()`, where the surplus
        // is FX movement instead.
        //
        // Underpayment is still refused, and that stays deliberate: a
        // subscription has a fixed price, so a customer who paid less has not
        // bought the plan. It also cannot be gamed in the other direction — a
        // subscription grants the plan whatever was paid, so there is nothing to
        // gain by overpaying.
        $verified = $verification->isPaid()
            && $verifiedKobo >= $expectedKobo
            && ($verifiedCurrency === '' || $verifiedCurrency === strtoupper((string) $payment->currency));

        if ($verified && $verifiedKobo > $expectedKobo) {
            // Logged rather than passed over: the surplus is real money in the
            // provider's account that the ledger does not know about, because
            // `postSubscriptionPayment()` books revenue from `$payment->amount`
            // — the price of the plan, which is the right figure for income and
            // the wrong one for a reconciliation against the bank.
            Log::info('api.management.subscription_payment_overpaid', [
                'payment_id' => $payment->id,
                'payment_gateway' => $code,
                'expected_kobo' => $expectedKobo,
                'verified_kobo' => $verifiedKobo,
                'surplus_kobo' => $verifiedKobo - $expectedKobo,
            ]);
        }

        return new GatewayVerificationResult(
            $verified,
            $verification->raw,
            $expectedKobo,
            $verifiedKobo,
            $verifiedCurrency !== '' ? $verifiedCurrency : strtoupper((string) $payment->currency),
            // The driver's name for this payment, which is what the merchant
            // path writes to `transactions.gateway_reference` and therefore what
            // a later delivery or reconcile matches on. Taken from the driver
            // rather than from `raw['id']` because the two differ exactly where
            // it matters: Bachs' payload is a charge with an `id` of its own,
            // and the name its webhooks quote is the *checkout* id beside it.
            $verification->providerId,
        );
    }

    /**
     * The provider's payload plus the two names the rest of this codebase asks
     * it by, so the idempotent replay and the repository do not need to know
     * which gateway produced it.
     *
     * The `id` here is the driver's own id for the *initialized* payment, which
     * is not the same thing as the name settlement writes to `gateway_reference`
     * — see {@see GatewayVerificationResult::$providerReference}. Nothing reads
     * it for that purpose; the replay needs `authorization_url` and the reports
     * want a link to the checkout, which is what `id` is used for.
     *
     * @return array<string, mixed>
     */
    public function normalizedGateway(InitializationResult $result): array
    {
        return array_filter([
            ...$result->raw,
            'id' => $result->providerId ?? $result->providerReference,
            'authorization_url' => $result->redirectUrl,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Activate a verified payment and fan out the post-commit work.
     *
     * Returns whether this call performed the activation; a concurrent
     * callback that already succeeded returns false and is left untouched.
     */
    public function activate(Payment $payment, GatewayVerificationResult $verification, ?User $requestUser): bool
    {
        [$activated, $exhaustedCoupon] = DB::transaction(function () use ($payment, $verification) {
            $lockedPayment = $this->payments->lockPaymentForUpdate($payment->id);

            if ($lockedPayment->status === Payment::STATUS_SUCCESS) {
                return [false, null];
            }

            $this->payments->markPaymentSuccessful($lockedPayment, $verification->gateway, $verification->providerReference);
            $this->payments->confirmTransactions($lockedPayment->reference, $verification->gateway, $verification->providerReference);

            $subscription = $this->payments->lockSubscriptionForUpdate($lockedPayment->subscription_id);
            $plan = $subscription->subscriptionPlan;

            $this->payments->activateSubscription($subscription, $plan, $lockedPayment);

            $this->payments->activateUser($lockedPayment->user_id);
            $this->payments->activatePendingStores($lockedPayment->business_id);

            $exhaustedCoupon = null;
            $couponCode = data_get($lockedPayment->metadata, 'coupon_code');
            if ($couponCode) {
                $coupon = $this->payments->lockCouponByCode($couponCode);
                if ($coupon && $coupon->isValid()) {
                    $this->payments->incrementCouponUse($coupon);
                    if ($coupon->isExhausted()) {
                        $this->payments->deactivateCoupon($coupon);
                        $exhaustedCoupon = $coupon;
                    }
                }
            }

            return [true, $exhaustedCoupon];
        });

        if ($activated) {
            $payment->refresh();
            // Notifications and ledger attribution follow the paying user, not
            // whoever opens the callback: a teammate with the subscription
            // permission can complete the return leg, and the owner still has
            // to be told and the journal line attributed to the payer.
            $user = User::query()->find($payment->user_id) ?? $requestUser;

            $this->activationNotifier->send($user);

            if ($exhaustedCoupon) {
                $this->notifyCouponExhausted($exhaustedCoupon);
            }

            $ledger = app(LedgerPostingService::class);
            $ledger->safe(fn () => $ledger->postSubscriptionPayment($payment, $user->id));

            Log::info('api.management.subscription_payment_confirmed', [
                'payment_id' => $payment->id,
                'user_id' => $user->id,
                'subscription_id' => $payment->subscription_id,
            ]);
        }

        return $activated;
    }

    /**
     * A fully-covering coupon activates immediately, with no gateway step.
     *
     * Only the activation itself belongs in this method: the controller's
     * failure handling wraps this call, so the notifications and the success
     * log stay outside it — the boundary the controller carried before the
     * workflow moved here.
     */
    public function activateWithCoupon(User $user, SubscriptionPlan $plan, Coupon $coupon): CouponActivationResult
    {
        return $this->couponActivator->execute($user, $plan, $coupon);
    }

    /**
     * The post-activation fan-out: exhaustion mail, activation notice and the
     * success log. Runs after the subscription is already active, so it must
     * not feed the activation-failure envelope.
     */
    public function announceCouponActivation(User $user, Coupon $coupon, CouponActivationResult $result): void
    {
        if ($result->couponExhausted) {
            $this->notifyCouponExhausted($result->coupon);
        }

        $this->activationNotifier->send($user);

        Log::info('api.management.subscription_activated_with_coupon', [
            'user_id' => $user->id,
            'subscription_id' => $result->subscription->id,
            'coupon_code' => $coupon->code,
        ]);
    }

    /**
     * Which gateway this payment was charged through.
     *
     * Written when the attempt is created and again when the provider answers,
     * so it is set for everything this codebase created. The fallback covers
     * rows written before the platform could bill through more than one
     * gateway: their column holds the schema default `'paystack'`, which is also
     * the truth for them.
     */
    private function chargedThrough(Payment $payment): string
    {
        $code = $payment->payment_gateway;

        return is_string($code) && $code !== '' ? $code : $this->billing->code();
    }

    private function markInitializationFailed(Payment $payment, Subscription $subscription, string $message): void
    {
        DB::transaction(function () use ($payment, $subscription, $message) {
            $this->payments->markPaymentFailed($payment, $message);
            $this->payments->cancelSubscription($subscription);
            $this->payments->cancelTransactions($payment->reference);
        });
    }

    private function markGatewayMismatch(Payment $payment): void
    {
        // The transaction is failed with the payment so it cannot sit
        // indefinitely pending in the generic transactions list.
        DB::transaction(function () use ($payment) {
            $this->payments->markPaymentFailed($payment, 'Gateway verification mismatch.');
            $this->payments->cancelTransactions($payment->reference, [
                'metadata' => [
                    'payment_id' => $payment->id,
                    'payment_type' => $payment->payment_type,
                    'failure_reason' => 'Gateway verification mismatch.',
                ],
            ]);
        });
    }

    private function notifyCouponExhausted(Coupon $coupon): void
    {
        $adminEmail = config('mail.admin_email', env('ADMIN_EMAIL'));
        if (! $adminEmail) {
            return;
        }

        try {
            Mail::to($adminEmail)->queue(new CouponExhaustedMail($coupon));
        } catch (Throwable $e) {
            Log::error('coupon.exhausted_email_failed', ['coupon_code' => $coupon->code, 'error' => $e->getMessage()]);
        }
    }
}
