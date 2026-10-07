<?php

namespace App\Http\Resources\Subscription;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The applied-coupon acknowledgement: the code is valid but does not cover the
 * whole plan, so it stays an applied coupon the checkout will re-price.
 *
 * @property array{coupon: Coupon, code: string, description: string, base_amount_kobo: ?int, discount_kobo: ?int} $resource
 */
final class CouponValidationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Coupon $coupon */
        $coupon = $this->resource['coupon'];
        $baseAmountKobo = $this->resource['base_amount_kobo'];
        $discountKobo = $this->resource['discount_kobo'];

        return [
            'valid' => true,
            'code' => $this->resource['code'],
            'activated' => false,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (string) $coupon->discount_value,
            'discount_label' => $coupon->discount_label,
            'plan_name' => $coupon->subscriptionPlan?->name,
            'plan_id' => $coupon->subscription_plan_id,
            'description' => $this->resource['description'],
            'base_amount_kobo' => $baseAmountKobo,
            'discount_kobo' => $discountKobo,
            'total_kobo' => $baseAmountKobo !== null ? max(0, $baseAmountKobo - $discountKobo) : null,
        ];
    }
}
