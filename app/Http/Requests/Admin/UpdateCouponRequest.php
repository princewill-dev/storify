<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-12 — `PUT /api/v1/admin/coupons/{coupon}` payload (OF-4.2 coupon edit
 * parity).
 *
 * The same update validation as the shared admin CouponController, with
 * `code` added (unique ignoring the coupon being edited) — that field is the
 * parity gap this route closes. Everything is "sometimes"/"nullable" so a
 * partial payload cannot wipe fields it never sent, and `code` is validated
 * as-submitted: the uppercase normalisation happens after validation, exactly
 * as it did before this extraction.
 *
 * The platform-admin guard stays in the controller on purpose: it must not
 * move into FormRequest::authorize() or middleware.
 */
class UpdateCouponRequest extends FormRequest
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
            'code' => ['sometimes', 'string', 'max:50', Rule::unique('coupons', 'code')->ignore($this->route('coupon')?->getKey())],
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
