<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * WS-12 — coupon edit parity (OF-4.2).
 *
 * The legacy edit page allowed changing the coupon **code**; the shared
 * admin CouponController's update() validation never accepted `code` and the
 * SPA disabled the field, so codes became immutable after creation. This
 * controller is the same update with `code` (unique ignoring self) added, and
 * the route module re-registers `PUT coupons/{coupon}` against it — the route
 * collection keys on method+URI, so a module file replaces the shared entry
 * without editing routes/api/v1/admin.php (the AD-08 pattern).
 *
 * The payload shape deliberately matches CouponController's so the existing
 * SPA type stays valid.
 */
class CouponUpdateController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function update(Request $request, Coupon $coupon): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('coupons', 'code')->ignore($coupon->getKey())],
            'name' => ['nullable', 'string', 'max:255'],
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'discount_type' => ['sometimes', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['sometimes', 'numeric', 'min:0.01'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        // Codes are stored uppercase (legacy uppercased on create and on edit).
        if (array_key_exists('code', $data)) {
            $data['code'] = strtoupper($data['code']);
        }

        if ($request->has('is_active')) {
            $data['is_active'] = $request->boolean('is_active');
        }

        $coupon->update($data);

        return $this->ok(['coupon' => $this->payload($coupon->fresh())], 'Coupon updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Coupon $coupon): array
    {
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
