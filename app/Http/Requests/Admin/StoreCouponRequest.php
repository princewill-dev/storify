<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-12 (admin console) — `POST /api/v1/admin/coupons` payload.
 *
 * The exact rule set CouponController::store() validated inline: `code` is
 * required and unique as submitted, `discount_type` is one of
 * percentage/fixed, and the discount amount has a 0.01 floor. Codes are still
 * uppercased after validation, in the controller, exactly where that step has
 * always been — the unique rule therefore keeps validating the client's own
 * casing.
 *
 * Authorization stays with the route middleware (`permission:admin.coupons`):
 * this controller has never carried an in-body platform-admin guard, and
 * moving one here would change who passes the gate.
 */
final class StoreCouponRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:50', 'unique:coupons,code'],
            'name' => ['nullable', 'string', 'max:255'],
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
