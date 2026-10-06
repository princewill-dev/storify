<?php

namespace App\Http\Requests\Management\PaymentSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-11 — the account-name resolution form (Paystack `bank/resolve`).
 */
class VerifyBankAccountRequest extends FormRequest
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
            'account_number' => ['required', 'string', 'digits:10'],
            'bank_code' => ['required', 'string', 'max:20'],
        ];
    }
}
