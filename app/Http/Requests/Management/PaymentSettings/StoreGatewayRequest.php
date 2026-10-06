<?php

namespace App\Http\Requests\Management\PaymentSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-11 — connecting a Paystack gateway for the business.
 */
class StoreGatewayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'public_key' => ['required', 'string', 'max:255'],
            'secret_key' => ['required', 'string', 'max:255'],
        ];
    }
}
