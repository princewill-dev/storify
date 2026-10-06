<?php

namespace App\Http\Requests\Management\PaymentSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-11 — the edit-bank-account form. Only the display name and the primary
 * flag are editable; the account itself is immutable.
 */
class UpdateBankAccountRequest extends FormRequest
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
            'account_name' => ['required', 'string', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
