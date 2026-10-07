<?php

namespace App\Http\Controllers\Api\V1\Management\Subscription;

use App\Actions\Subscriptions\ActivateSubscriptionWithCoupon;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Subscription\ValidateCouponRequest;
use App\Http\Resources\Subscription\CouponActivationResource;
use App\Http\Resources\Subscription\CouponValidationResource;
use App\Models\Coupon;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Repositories\Subscription\SubscriptionPaymentRepository;
use App\Services\Subscription\SubscriptionPaymentService;
use App\Services\Subscription\SubscriptionPricingService;
use App\Support\Money\Naira;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WS-32 — coupon redemption from the plans page and the checkout.
 *
 * Legacy stashed the applied code in the server session (`applied_coupon_code`)
 * and `SubscriptionPaymentController@initialize` read it back. The API is
 * stateless, so the handoff is the `coupon_code` parameter WS-08's checkout
 * endpoints already accept: this controller validates, the SPA keeps the
 * applied code (src/stores/coupon.ts) and sends it with the payment call.
 *
 * Two verify-identified legacy defects are fixed rather than cloned:
 *  - `plans.remove-coupon` was missing from `CheckSubscription`'s exempt list,
 *    so a gated owner's removal POST was bounced and the chip stuck forever;
 *  - the per-plan applicability check never ran because the legacy UI never
 *    sent `plan_id`, and a coupon that failed it at checkout was silently
 *    dropped (full price, chip still shown). Here the SPA always sends the
 *    plan it intends to pay for, and a mismatch answers with the legacy
 *    "only applies to {plan}" message instead of disappearing.
 */
class CouponController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly ActivateSubscriptionWithCoupon $activator,
        private readonly SubscriptionPaymentRepository $payments,
        private readonly SubscriptionPricingService $pricing,
        private readonly SubscriptionPaymentService $paymentService,
    ) {}

    /**
     * Validate a code against the plan the shopper intends to pay for.
     *
     * A fully-covering coupon activates the subscription on the spot — no
     * payment step — and hands back a redirect. That is reachable from the
     * plans page and the checkout alike, unlike legacy where the plans-page
     * branch only fired for a plan-scoped coupon.
     */
    public function validateCoupon(ValidateCouponRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = $this->user($request);
        $code = strtoupper(trim($data['code']));

        // Platform coupons carry no business; a business-scoped coupon must
        // belong to the authenticated business (never another tenant's).
        $coupon = $this->payments->findCouponByCode($code, $user->business_id);

        if (! $coupon?->isValid()) {
            return $this->error('Invalid or expired coupon code.', 422);
        }

        if (! empty($data['plan_id']) && ! $coupon->isApplicableTo((int) $data['plan_id'])) {
            $planName = $coupon->subscriptionPlan?->name ?? 'another plan';

            return $this->error("This coupon only applies to {$planName}.", 422);
        }

        // The plan the discount will land on: an explicit plan from the SPA
        // first, then the coupon's own plan (legacy's only route to instant
        // activation). A generic coupon with no plan stays an applied coupon —
        // WS-08's checkout activates it when the total reaches zero.
        $targetPlan = ! empty($data['plan_id'])
            ? SubscriptionPlan::query()->active()->find((int) $data['plan_id'])
            : $coupon->subscriptionPlan;

        if (! empty($data['plan_id']) && ! $targetPlan) {
            return $this->error('Selected plan is no longer available.', 422);
        }

        if ($targetPlan && $this->activator->fullyCovers($coupon, $targetPlan)) {
            return $this->activate($user, $coupon, $targetPlan);
        }

        // koboFromStrict is the contract this controller already carried: a
        // malformed amount converts to zero rather than being guessed at. The
        // percentage/fixed arithmetic lives in SubscriptionPricingService.
        $baseAmountKobo = $targetPlan ? Naira::koboFromStrict($targetPlan->amount) : null;
        $discountKobo = $baseAmountKobo !== null ? $this->pricing->discountKobo($coupon, $baseAmountKobo) : null;

        Log::info('api.management.coupon_validated', [
            'user_id' => $user->id,
            'coupon_code' => $coupon->code,
            'plan_id' => $targetPlan?->id,
        ]);

        return $this->ok(
            CouponValidationResource::make([
                'coupon' => $coupon,
                'code' => $code,
                // Legacy's sentence: what the coupon gives and where it applies.
                'description' => $this->discountSentence($coupon).' applied! The discount is shown at checkout.',
                'base_amount_kobo' => $baseAmountKobo,
                'discount_kobo' => $discountKobo,
            ])->resolve(),
            'Coupon applied. The discount is shown at checkout.',
        );
    }

    /**
     * Clear the applied code. Stateless — the SPA drops its stored coupon and
     * simply stops sending `coupon_code`, so this is the acknowledgement the
     * UI needs. Reachable while unsubscribed on purpose (see the class note).
     */
    public function removeCoupon(Request $request): JsonResponse
    {
        Log::info('api.management.coupon_removed', ['user_id' => $this->user($request)->id]);

        return $this->ok(['removed' => true], 'Coupon removed.');
    }

    /**
     * The full-cover path: activate now, notify, and tell the SPA where to go.
     *
     * The activation workflow and the post-activation fan-out are shared with
     * WS-08's checkout coupon path — see SubscriptionPaymentService.
     */
    private function activate(User $user, Coupon $coupon, SubscriptionPlan $plan): JsonResponse
    {
        try {
            $result = $this->paymentService->activateWithCoupon($user, $plan, $coupon);
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            Log::error('api.management.coupon_activation_failed', [
                'user_id' => $user->id,
                'coupon_code' => $coupon->code,
                'plan_id' => $plan->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Could not activate this plan. Please try again.', 500);
        }

        $this->paymentService->announceCouponActivation($user, $coupon, $result);

        return $this->ok(
            CouponActivationResource::make([
                'coupon' => $coupon,
                'plan' => $plan,
                'description' => $this->discountSentence($coupon).' — applied to '.$plan->name.'.',
                'subscription' => $result->subscription,
                'coupon_exhausted' => $result->couponExhausted,
            ])->resolve(),
            $plan->name.' activated! Taking you to your dashboard…',
        );
    }

    /**
     * "20% off on Growth Monthly" / "₦5,000.00 off on any plan" — the legacy
     * sentence, minus the tick the API messages do not carry.
     */
    private function discountSentence(Coupon $coupon): string
    {
        $discount = $coupon->discount_type === 'percentage'
            ? number_format((float) $coupon->discount_value, 0).'% off'
            : '₦'.number_format((float) $coupon->discount_value, 2).' off';
        $scope = $coupon->subscriptionPlan ? ' on '.$coupon->subscriptionPlan->name : ' on any plan';

        return $discount.$scope;
    }
}
