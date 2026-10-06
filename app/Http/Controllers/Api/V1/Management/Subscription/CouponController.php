<?php

namespace App\Http\Controllers\Api\V1\Management\Subscription;

use App\Actions\Subscriptions\ActivateSubscriptionWithCoupon;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Mail\CouponExhaustedMail;
use App\Models\Coupon;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\StoreActivationNotifier;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
        private readonly StoreActivationNotifier $activationNotifier,
    ) {}

    /**
     * Validate a code against the plan the shopper intends to pay for.
     *
     * A fully-covering coupon activates the subscription on the spot — no
     * payment step — and hands back a redirect. That is reachable from the
     * plans page and the checkout alike, unlike legacy where the plans-page
     * branch only fired for a plan-scoped coupon.
     */
    public function validateCoupon(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100'],
            'plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
        ]);

        $user = $this->user($request);
        $code = strtoupper(trim($data['code']));

        // Platform coupons carry no business; a business-scoped coupon must
        // belong to the authenticated business (never another tenant's).
        $coupon = Coupon::query()
            ->where('code', $code)
            ->where(fn ($query) => $query->whereNull('business_id')->orWhere('business_id', $user->business_id))
            ->first();

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

        $baseAmountKobo = $targetPlan ? $this->toKobo($targetPlan->amount) : null;
        $discountKobo = $baseAmountKobo !== null ? $this->discountKobo($coupon, $baseAmountKobo) : null;

        Log::info('api.management.coupon_validated', [
            'user_id' => $user->id,
            'coupon_code' => $coupon->code,
            'plan_id' => $targetPlan?->id,
        ]);

        return $this->ok([
            'valid' => true,
            'code' => $code,
            'activated' => false,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (string) $coupon->discount_value,
            'discount_label' => $coupon->discount_label,
            'plan_name' => $coupon->subscriptionPlan?->name,
            'plan_id' => $coupon->subscription_plan_id,
            // Legacy's sentence: what the coupon gives and where it applies.
            'description' => $this->discountSentence($coupon).' applied! The discount is shown at checkout.',
            'base_amount_kobo' => $baseAmountKobo,
            'discount_kobo' => $discountKobo,
            'total_kobo' => $baseAmountKobo !== null ? max(0, $baseAmountKobo - $discountKobo) : null,
        ], 'Coupon applied. The discount is shown at checkout.');
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
     */
    private function activate(User $user, Coupon $coupon, SubscriptionPlan $plan): JsonResponse
    {
        try {
            $result = $this->activator->execute($user, $plan, $coupon);
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

        if ($result->couponExhausted) {
            $this->notifyCouponExhausted($result->coupon);
        }

        $this->activationNotifier->send($user);

        Log::info('api.management.coupon_activated', [
            'user_id' => $user->id,
            'subscription_id' => $result->subscription->id,
            'coupon_code' => $coupon->code,
        ]);

        return $this->ok([
            'valid' => true,
            'code' => $coupon->code,
            'activated' => true,
            'redirect' => '/',
            'discount_type' => $coupon->discount_type,
            'discount_value' => (string) $coupon->discount_value,
            'discount_label' => $coupon->discount_label,
            'plan_name' => $plan->name,
            'plan_id' => $plan->id,
            // The whole plan amount is covered, so the payable total is zero.
            'description' => $this->discountSentence($coupon).' — applied to '.$plan->name.'.',
            'base_amount_kobo' => $this->toKobo($plan->amount),
            'discount_kobo' => $this->toKobo($plan->amount),
            'total_kobo' => 0,
            'subscription' => [
                'id' => $result->subscription->id,
                'subscription_code' => $result->subscription->subscription_code,
                'status' => $result->subscription->status,
                'starts_at' => $result->subscription->starts_at?->toISOString(),
                'expires_at' => $result->subscription->expires_at?->toISOString(),
                'plan_name' => $plan->name,
            ],
            'coupon_exhausted' => $result->couponExhausted,
        ], $plan->name.' activated! Taking you to your dashboard…');
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

    private function notifyCouponExhausted(Coupon $coupon): void
    {
        $adminEmail = config('mail.admin_email');
        if (! $adminEmail) {
            return;
        }

        try {
            Mail::to($adminEmail)->queue(new CouponExhaustedMail($coupon));
        } catch (Throwable $e) {
            Log::error('coupon.exhausted_email_failed', ['coupon_code' => $coupon->code, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Percentage coupons store "20.00" meaning 20%; basis points (2000) keep
     * the discount arithmetic integer-only.
     */
    private function discountKobo(Coupon $coupon, int $amountKobo): int
    {
        if ($coupon->discount_type === 'percentage') {
            return intdiv($amountKobo * $this->toKobo($coupon->discount_value), 10000);
        }

        return min($this->toKobo($coupon->discount_value), $amountKobo);
    }

    /**
     * Naira decimal string -> integer kobo, without floating point.
     */
    private function toKobo(string|int|float|null $amount): int
    {
        $value = trim((string) $amount);

        if ($value === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return 0;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $kobo = ((int) $whole) * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$kobo : $kobo;
    }
}
