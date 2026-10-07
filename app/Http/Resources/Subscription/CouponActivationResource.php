<?php

namespace App\Http\Resources\Subscription;

use App\Models\Coupon;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full-cover activation answer: the subscription is active, the coupon has
 * been consumed and the SPA is told where to go.
 *
 * @property array{coupon: Coupon, plan: SubscriptionPlan, description: string, subscription: Subscription, coupon_exhausted: bool} $resource
 */
final class CouponActivationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Coupon $coupon */
        $coupon = $this->resource['coupon'];
        /** @var SubscriptionPlan $plan */
        $plan = $this->resource['plan'];

        return [
            'valid' => true,
            'code' => $coupon->code,
            'activated' => true,
            'redirect' => '/',
            'discount_type' => $coupon->discount_type,
            'discount_value' => (string) $coupon->discount_value,
            'discount_label' => $coupon->discount_label,
            'plan_name' => $plan->name,
            'plan_id' => $plan->id,
            'description' => $this->resource['description'],
            'base_amount_kobo' => Naira::koboFromStrict($plan->amount),
            'discount_kobo' => Naira::koboFromStrict($plan->amount),
            // The whole plan amount is covered, so the payable total is zero.
            'total_kobo' => 0,
            'subscription' => SubscriptionResource::make($this->resource['subscription'])->resolve($request),
            'coupon_exhausted' => $this->resource['coupon_exhausted'],
        ];
    }
}
