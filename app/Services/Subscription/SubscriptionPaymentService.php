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
use App\Services\PaystackService;
use App\Services\StoreActivationNotifier;
use App\Support\Money\Naira;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The WS-08 money path's atomic workflows: the pending attempt triple, the
 * gateway call with its failure bookkeeping and the verified activation with
 * its post-commit mail, notifications and ledger posting.
 */
final class SubscriptionPaymentService
{
    public function __construct(
        private readonly PaystackService $paystack,
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
     * Send the pending attempt to Paystack. A failed initialization is
     * marked on the payment, subscription and transaction inside one
     * transaction so it cannot sit indefinitely pending.
     *
     * @return array<string, mixed> the gateway result
     */
    public function initializeWithGateway(
        User $user,
        SubscriptionPlan $plan,
        Subscription $subscription,
        Payment $payment,
        int $totalKobo,
        ?string $callbackUrl,
    ): array {
        $result = $this->paystack->initializePayment([
            'email' => $user->email,
            'amount' => $totalKobo,
            'currency' => $payment->currency,
            'reference' => $payment->reference,
            'callback_url' => $callbackUrl ?? url('/subscription/callback'),
            'metadata' => [
                'user_id' => $user->id,
                'business_id' => $user->business_id,
                'payment_id' => $payment->id,
                'subscription_id' => $subscription->id,
                'plan_name' => $plan->name,
            ],
        ]);

        if (! ($result['success'] ?? false)) {
            $this->markInitializationFailed($payment, $subscription, $result['message'] ?? 'Initialization failed.');

            return $result;
        }

        $this->payments->setPaymentGatewayResponse($payment, $result['data']);

        return $result;
    }

    /**
     * Double-verify the payment with Paystack and fail it — payment and
     * transaction together — when the gateway disagrees on status, amount or
     * currency.
     *
     * koboFromStrict is deliberate: a malformed stored amount must convert to
     * zero and fail the comparison rather than match a guessed value.
     */
    public function verifyGatewayPayment(Payment $payment): GatewayVerificationResult
    {
        $verification = $this->paystack->doubleVerifyPayment($payment->reference);
        $gateway = $verification['data'] ?? [];
        $expectedKobo = Naira::koboFromStrict($payment->amount);
        $verifiedKobo = (int) ($gateway['amount'] ?? -1);
        $verifiedCurrency = strtoupper((string) ($gateway['currency'] ?? ''));

        $verified = ($verification['success'] ?? false)
            && strtolower((string) ($gateway['status'] ?? '')) === 'success'
            && $verifiedKobo === $expectedKobo
            && $verifiedCurrency === strtoupper((string) $payment->currency);

        if (! $verified) {
            $this->markGatewayMismatch($payment);
        }

        return new GatewayVerificationResult($verified, $gateway, $expectedKobo, $verifiedKobo, $verifiedCurrency);
    }

    /**
     * Activate a verified payment and fan out the post-commit work.
     *
     * Returns whether this call performed the activation; a concurrent
     * callback that already succeeded returns false and is left untouched.
     */
    public function activate(Payment $payment, array $gateway, ?User $requestUser): bool
    {
        [$activated, $exhaustedCoupon] = DB::transaction(function () use ($payment, $gateway) {
            $lockedPayment = $this->payments->lockPaymentForUpdate($payment->id);

            if ($lockedPayment->status === Payment::STATUS_SUCCESS) {
                return [false, null];
            }

            $this->payments->markPaymentSuccessful($lockedPayment, $gateway);
            $this->payments->confirmTransactions($lockedPayment->reference, $gateway);

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
