<?php

namespace App\Http\Resources\Subscription;

use App\Models\Coupon;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * The WS-08 checkout summary, assembled from the plan, coupon, amounts and
 * lapsed subscription the controller resolved.
 *
 * @property array{plan: SubscriptionPlan, coupon: ?Coupon, user: User, payment_type: string, plan_amount_kobo: int, discount_kobo: int, total_kobo: int, expired_subscription: ?Subscription} $resource
 */
final class CheckoutSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SubscriptionPlan $plan */
        $plan = $this->resource['plan'];
        /** @var ?Coupon $coupon */
        $coupon = $this->resource['coupon'];
        /** @var User $user */
        $user = $this->resource['user'];
        /** @var ?Subscription $expiredSubscription */
        $expiredSubscription = $this->resource['expired_subscription'];
        $billing = app(PlatformBillingGateway::class);

        return [
            'plan' => PlanResource::make($plan)->resolve($request),
            'payment_type' => $this->resource['payment_type'],
            'base_amount_kobo' => $this->resource['plan_amount_kobo'],
            'discount_kobo' => $this->resource['discount_kobo'],
            'total_kobo' => $this->resource['total_kobo'],
            'total' => Naira::decimalFromKobo($this->resource['total_kobo']),
            'currency' => $plan->currency,
            'coupon' => $coupon ? CouponResource::make($coupon)->resolve($request) : null,
            'trial' => [
                'on_trial' => $user->isOnTrial(),
                'days_left' => $user->daysLeftOnTrial(),
                'ends_at' => $user->trial_ends_at?->toISOString(),
            ],
            // Legacy regenerated a UUID per page view; the SPA keeps it for the
            // whole checkout attempt so retries cannot double-charge.
            'idempotency_key' => (string) Str::uuid(),
            // Who will take the money, so the checkout screen can say so. The
            // name comes from the seam rather than from a literal: the SPA used
            // to print "Paystack" no matter which gateway an admin had selected,
            // which is a lie about who is charging the customer.
            'gateway' => [
                'code' => $billing->code(),
                'name' => $billing->name(),
            ],
            'gateway_available' => $billing->available(),
            'expired_subscription' => $expiredSubscription
                ? ExpiredSubscriptionResource::make($expiredSubscription)->resolve($request)
                : null,
        ];
    }
}
