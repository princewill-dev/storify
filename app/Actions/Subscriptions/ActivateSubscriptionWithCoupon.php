<?php

namespace App\Actions\Subscriptions;

use App\Models\Business;
use App\Models\Coupon;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ActivateSubscriptionWithCoupon
{
    public function fullyCovers(Coupon $coupon, SubscriptionPlan $plan): bool
    {
        if ($coupon->discount_type === 'percentage') {
            return (float) $coupon->discount_value >= 100;
        }

        return (float) $coupon->discount_value >= (float) $plan->amount;
    }

    public function execute(User $user, SubscriptionPlan $plan, Coupon $coupon): CouponActivationResult
    {
        return DB::transaction(function () use ($user, $plan, $coupon): CouponActivationResult {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $lockedCoupon = Coupon::query()->lockForUpdate()->findOrFail($coupon->id);

            if (! $lockedUser->business_id) {
                throw new DomainException('Complete business setup before activating a subscription.');
            }

            Business::query()->lockForUpdate()->findOrFail($lockedUser->business_id);

            if (! $lockedCoupon->isValid()
                || ! $lockedCoupon->isApplicableTo($plan->id)
                || ! $this->fullyCovers($lockedCoupon, $plan)) {
                throw new DomainException('This coupon is no longer valid for the selected plan.');
            }

            $hasActiveSubscription = Subscription::query()
                ->where('business_id', $lockedUser->business_id)
                ->active()
                ->exists();

            if ($hasActiveSubscription) {
                throw new DomainException('You already have an active subscription.');
            }

            $subscription = Subscription::create([
                'user_id' => $lockedUser->id,
                'business_id' => $lockedUser->business_id,
                'subscription_plan_id' => $plan->id,
                'status' => Subscription::STATUS_ACTIVE,
                'starts_at' => now(),
                'expires_at' => $plan->interval === 'yearly' ? now()->addYear() : now()->addMonth(),
                'metadata' => [
                    'coupon_code' => $lockedCoupon->code,
                    'coupon_id' => $lockedCoupon->id,
                    'activated_at' => now()->toDateTimeString(),
                    'payment_skipped' => true,
                ],
            ]);

            $lockedUser->update([
                'trial_ends_at' => null,
                'selected_plan_id' => $plan->id,
                'status' => 'active',
                'is_verified' => true,
            ]);

            Store::query()
                ->where('business_id', $lockedUser->business_id)
                ->whereIn('status', [Store::STATUS_PENDING, Store::STATUS_SUSPENDED])
                ->update(['status' => Store::STATUS_ACTIVE]);

            $lockedCoupon->increment('uses_count');
            $lockedCoupon->refresh();
            $couponExhausted = $lockedCoupon->isExhausted();

            if ($couponExhausted) {
                $lockedCoupon->update(['is_active' => false]);
            }

            return new CouponActivationResult($subscription, $lockedCoupon, $couponExhausted);
        });
    }
}
