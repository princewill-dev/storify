<?php

namespace App\Http\Resources\Admin;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-12 (admin console) — the coupon row, shaped exactly as the admin coupon
 * endpoints have always emitted it.
 *
 * Field names, types and order are load-bearing: exact-JSON assertions depend
 * on them, so `discount_value` and `uses_count` are cast at the edge,
 * `expires_at` is an ISO-8601 string or null, and `plan` resolves through the
 * lazily-loaded plan relation. The payload deliberately matches the shared
 * CouponController's so the existing SPA type stays valid.
 *
 * @property-read Coupon $resource
 */
class CouponResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Coupon $coupon */
        $coupon = $this->resource;

        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'name' => $coupon->name,
            'plan' => $coupon->subscriptionPlan?->name,
            'subscription_plan_id' => $coupon->subscription_plan_id,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'discount_label' => $coupon->discount_label,
            'uses_count' => (int) $coupon->uses_count,
            'max_uses' => $coupon->max_uses,
            'expires_at' => $coupon->expires_at?->toISOString(),
            'is_active' => (bool) $coupon->is_active,
        ];
    }
}
