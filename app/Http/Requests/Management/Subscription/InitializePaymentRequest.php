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
            'payment_type' => ['nullable', Rule::in([Payment::TYPE_SUBSCRIPTION, Payment::TYPE_RENEWAL])],
            'callback_url' => ['nullable', 'url', 'max:255'],
        ];
    }
}
