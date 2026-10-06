<?php

namespace App\Http\Controllers\Api\V1\Management\Subscription;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Subscription\CheckoutSummaryRequest;
use App\Http\Requests\Management\Subscription\InitializePaymentRequest;
use App\Http\Requests\Management\Subscription\PaymentCallbackRequest;
use App\Http\Requests\Management\Subscription\PaymentHistoryRequest;
use App\Http\Resources\Subscription\CheckoutSummaryResource;
use App\Http\Resources\Subscription\PaymentCallbackResource;
use App\Http\Resources\Subscription\PaymentResource;
use App\Http\Resources\Subscription\SubscriptionResource;
use App\Models\Coupon;
use App\Models\Payment;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Repositories\Subscription\SubscriptionPaymentRepository;
use App\Services\Subscription\SubscriptionPaymentService;
use App\Services\Subscription\SubscriptionPricingService;
use App\Support\Money\Naira;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The subscription money path — checkout, idempotent Paystack initialization
 * and the verified callback that activates the business.
 *
 * Money moves through kobo integers here: the tables keep naira decimals for
 * backwards compatibility, but every calculation (discount, total, gateway
 * comparison) converts to kobo first so no float rounding can creep in.
 */
class PaymentController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly SubscriptionPaymentRepository $payments,
        private readonly SubscriptionPaymentService $paymentService,
        private readonly SubscriptionPricingService $pricing,
    ) {}

    /**
     * Checkout summary: the plan about to be paid for, the coupon-adjusted
     * total and a fresh idempotency key.
     */
    public function show(CheckoutSummaryRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $user = $this->user($request);
        $businessId = $this->businessId($request);
        $paymentType = $filters['payment_type'] ?? Payment::TYPE_SUBSCRIPTION;

        if ($this->payments->businessHasActiveSubscription($businessId)) {
            return $this->error('You already have an active subscription.', 409);
        }

        $quote = $this->pricing->quote(
            $user,
            $paymentType,
            $businessId,
            $filters['coupon_code'] ?? null,
            allowDefault: $paymentType === Payment::TYPE_RENEWAL,
        );

        if (! $quote->plan) {
            return $this->error(
                $user->selected_plan_id ? 'Selected plan is no longer available.' : 'Please select a plan first.',
                422,
            );
        }

        if ($quote->couponError) {
            return $this->error($quote->couponError, 422);
        }

        return $this->ok(CheckoutSummaryResource::make([
            'plan' => $quote->plan,
            'coupon' => $quote->coupon,
            'user' => $user,
            'payment_type' => $paymentType,
            'plan_amount_kobo' => $quote->planAmountKobo,
            'discount_kobo' => $quote->discountKobo,
            'total_kobo' => $quote->totalKobo,
            'expired_subscription' => $this->payments->expiredSubscriptionFor($businessId),
        ])->resolve());
    }

    /**
     * Initialize (or replay) a Paystack payment.
     */
    public function initialize(InitializePaymentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = $this->user($request);
        $businessId = $this->businessId($request);
        $paymentType = $data['payment_type'] ?? Payment::TYPE_SUBSCRIPTION;

        if ($this->payments->businessHasActiveSubscription($businessId)) {
            return $this->error('You already have an active subscription.', 409);
        }

        $quote = $this->pricing->quote($user, $paymentType, $businessId, $data['coupon_code'] ?? null, allowDefault: true);

        if (! $quote->plan) {
            return $this->error('No subscription plan available.', 422);
        }

        // Legacy silently dropped a non-applicable or exhausted coupon here
        // and charged full price with no message — don't.
        if ($quote->couponError) {
            return $this->error($quote->couponError, 422);
        }

        $plan = $quote->plan;
        $coupon = $quote->coupon;
        $totalKobo = $quote->totalKobo;

        if ($coupon && $totalKobo <= 0) {
            return $this->activateWithCoupon($user, $plan, $coupon);
        }

        // Idempotency contract: re-submitting a key re-redirects to the stored
        // authorization URL instead of creating a second charge.
        $existing = $this->payments->findByIdempotencyKey($businessId, $data['idempotency_key']);
        if ($existing) {
            return $this->replayedResponse($existing);
        }

        try {
            [$subscription, $payment] = $this->paymentService->startAttempt(
                $user,
                $plan,
                $coupon,
                $data['idempotency_key'],
                $paymentType,
                $request->ip(),
                $quote->planAmountKobo,
                $quote->discountKobo,
                $totalKobo,
            );
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same key won the race; hand back
            // whatever it stored rather than creating a second charge.
            $existing = $this->payments->findByIdempotencyKey($businessId, $data['idempotency_key']);

            return $existing
                ? $this->replayedResponse($existing)
                : $this->error('This payment attempt could not be initialized. Reload and try again.', 422);
        }

        $result = $this->paymentService->initializeWithGateway(
            $user,
            $plan,
            $subscription,
            $payment,
            $totalKobo,
            $data['callback_url'] ?? null,
        );

        if (! ($result['success'] ?? false)) {
            Log::warning('api.management.subscription_payment_initialize_failed', [
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
            ]);

            return $this->error('Failed to initialize payment. Please try again.', 502);
        }

        Log::info('api.management.subscription_payment_initialized', [
            'user_id' => $user->id,
            'payment_id' => $payment->id,
            'reference' => $payment->reference,
            'amount_kobo' => $totalKobo,
        ]);

        return $this->ok([
            'redirect_url' => $result['data']['authorization_url'] ?? null,
            'reference' => $payment->reference,
            'payment_id' => $payment->id,
            'subscription_id' => $subscription->id,
            'total_kobo' => $totalKobo,
            'total' => Naira::decimalFromKobo($totalKobo),
        ], 'Payment initialized. Redirecting to Paystack.');
    }

    /**
     * Paystack returns the browser here (via the SPA callback route). The
     * payment is verified a second time against the gateway before anything
     * is activated.
     */
    public function callback(PaymentCallbackRequest $request): JsonResponse
    {
        $data = $request->validated();

        $payment = $this->payments->findForBusinessByReference($this->businessId($request), $data['reference']);

        if (! $payment) {
            return $this->error('Payment record not found.', 404);
        }

        if (! $payment->subscription_id) {
            return $this->error('This payment is not a subscription payment.', 422);
        }

        if ($payment->status === Payment::STATUS_SUCCESS) {
            return $this->ok(
                PaymentCallbackResource::alreadyProcessed($payment),
                'Payment already processed successfully.',
            );
        }

        $verification = $this->paymentService->verifyGatewayPayment($payment);

        if (! $verification->verified) {
            Log::warning('api.management.subscription_payment_verification_failed', [
                'payment_id' => $payment->id,
                'reference' => $payment->reference,
                'expected_kobo' => $verification->expectedKobo,
                'verified_kobo' => $verification->verifiedKobo,
                'verified_currency' => $verification->verifiedCurrency,
            ]);

            return $this->error('Payment verification failed.', 422);
        }

        $activated = $this->paymentService->activate($payment, $verification->gateway, $this->user($request));

        $payment = $payment->fresh();

        return $this->ok(
            PaymentCallbackResource::make(['payment' => $payment, 'activated' => $activated])->resolve(),
            $activated ? 'Subscription payment successful.' : 'Payment already processed successfully.',
        );
    }

    /**
     * Billing history. Scoped to the business rather than the *active*
     * subscription so payments do not vanish when a term lapses (legacy
     * scoped it to the active subscription and lost the history).
     */
    public function payments(PaymentHistoryRequest $request): JsonResponse
    {
        $filters = $request->validated();

        $payments = $this->payments->paginateForBusiness(
            $this->businessId($request),
            $filters['status'] ?? null,
            $filters['per_page'] ?? 20,
        );

        return $this->ok(
            $payments->getCollection()->map(fn (Payment $payment) => PaymentResource::make($payment)->resolve())->all(),
            null,
            200,
            $this->paginationMeta($payments),
        );
    }

    /**
     * A fully-covering coupon activates immediately, with no gateway step.
     */
    private function activateWithCoupon(User $user, SubscriptionPlan $plan, Coupon $coupon): JsonResponse
    {
        try {
            $result = $this->paymentService->activateWithCoupon($user, $plan, $coupon);
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Log::error('api.management.subscription_coupon_activation_failed', [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'coupon_code' => $coupon->code,
                'error' => $e->getMessage(),
            ]);

            return $this->error('The subscription could not be activated. Please try again.', 500);
        }

        $this->paymentService->announceCouponActivation($user, $coupon, $result);

        return $this->ok([
            'activated' => true,
            'redirect' => '/',
            'subscription' => SubscriptionResource::make($result->subscription)->resolve(),
        ], $plan->name.' activated successfully.');
    }

    private function replayedResponse(Payment $payment): JsonResponse
    {
        $url = data_get($payment->gateway_response, 'authorization_url');

        if (! $url) {
            return $this->error('This payment attempt could not be initialized. Reload and try again.', 422);
        }

        return $this->ok([
            'redirect_url' => $url,
            'reference' => $payment->reference,
            'payment_id' => $payment->id,
            'already_initialized' => true,
        ], 'This payment attempt is already in progress. Redirecting to the gateway.');
    }

    private function businessId(Request $request): ?int
    {
        return $this->user($request)->business_id;
    }
}
