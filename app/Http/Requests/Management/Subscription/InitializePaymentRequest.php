<?php

namespace App\Http\Requests\Management\Subscription;

use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitializePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:100'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            // `plan_change` is listed here because the checkout has to be able
            // to price the prorated difference a recorded change was quoted at
            // — the controller still refuses it unless such a change exists.
            'payment_type' => ['nullable', Rule::in([
                Payment::TYPE_SUBSCRIPTION,
                Payment::TYPE_RENEWAL,
                Payment::TYPE_PLAN_CHANGE,
            ])],
            'callback_url' => ['nullable', 'url', 'max:255'],
        ];
    }
}
