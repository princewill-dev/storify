<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SelectPaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method' => ['required', Rule::in(['paystack', 'bank_transfer'])],
            'amount' => ['nullable', 'numeric', 'min:1'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ];
    }
}
