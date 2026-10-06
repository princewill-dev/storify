<?php

namespace App\Services\Subscription;

use App\Models\Coupon;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Repositories\Subscription\SubscriptionPaymentRepository;
use App\Support\Money\Naira;

/**
 * Plan resolution, coupon rules and integer-kobo pricing for the WS-08
 * checkout. The arithmetic stays in kobo throughout — see Naira.
 */
final class SubscriptionPricingService
{
    public function __construct(
        private readonly SubscriptionPaymentRepository $payments,
    ) {}

    /**
     * Resolve the plan, apply the optional coupon and price the checkout.
     *
     * The checkout page only ever shows a plan the user chose; the
     * default-plan fallback belongs to the initialization endpoint. A renewal
     * with no history is really a first purchase, so it may fall back too.
     */
    public function quote(User $user, string $paymentType, ?int $businessId, ?string $couponCode, bool $allowDefault): CheckoutQuote
    {
        $plan = $this->payments->resolvePlan($user, $paymentType, $businessId, $allowDefault);

        if (! $plan) {
            return new CheckoutQuote(null, null, 0, 0, 0, null);
        }

        $planAmountKobo = Naira::koboFromStrict($plan->amount);
        $coupon = null;
        $discountKobo = 0;
        $couponError = null;

        if (! empty($couponCode)) {
            [$coupon, $couponError] = $this->resolveCoupon($couponCode, $plan, $businessId);

            if ($couponError) {
                $coupon = null;
            } else {
                $discountKobo = $this->discountKobo($coupon, $planAmountKobo);
            }
        }

        return new CheckoutQuote(
            $plan,
            $coupon,
            $planAmountKobo,
            $discountKobo,
            max(0, $planAmountKobo - $discountKobo),
            $couponError,
        );
    }

    /**
     * @return array{0: ?Coupon, 1: ?string} coupon and the error message, if any
     */
    public function resolveCoupon(string $code, SubscriptionPlan $plan, ?int $businessId): array
    {
        $coupon = $this->payments->findCouponByCode($code, $businessId);

        if (! $coupon?->isValid()) {
            return [null, 'Invalid or expired coupon code.'];
        }

        if (! $coupon->isApplicableTo($plan->id)) {
            $planName = $coupon->subscriptionPlan?->name ?? 'another plan';

            return [null, "This coupon only applies to {$planName}."];
        }

        return [$coupon, null];
    }

    /**
     * Percentage coupons store "20.00" meaning 20%; converting to basis
     * points (2000) keeps the discount arithmetic integer-only.
     */
    public function discountKobo(Coupon $coupon, int $amountKobo): int
    {
        if ($coupon->discount_type === 'percentage') {
            return Naira::percentOfKobo($amountKobo, Naira::koboFromStrict($coupon->discount_value));
        }

        return min(Naira::koboFromStrict($coupon->discount_value), $amountKobo);
    }
}
