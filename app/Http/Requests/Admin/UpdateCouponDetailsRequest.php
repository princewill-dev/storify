<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-12 (admin console) — `PUT /api/v1/admin/coupons/{coupon}` payload for
 * the shared CouponController::update().
 *
 * Deliberately without `code`: this update has never accepted it, so codes
 * stay immutable through this registration. Editing the code is the OF-4.2
 * parity gap owned by CouponUpdateController — the route module re-registers
 * this method+URI against that controller, which validates through its own
 * `UpdateCouponRequest` (same rules plus the unique-ignoring-self `code`) —
 * hence this class's distinct name.
 *
 * Everything is "sometimes"/"nullable" so a partial payload cannot wipe a
 * field it never sent. `is_active` is read with has()/boolean() in the
 * controller because an omitted flag is not the same as an explicit `false`.
 *
 * Authorization stays with the route middleware (`permission:admin.coupons`):
 * there is no in-body platform-admin guard on this controller to move.
 */
final class UpdateCouponDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:255'],
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'discount_type' => ['sometimes', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['sometimes', 'numeric', 'min:0.01'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
