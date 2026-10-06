<?php

namespace App\Http\Requests\Management\PaymentSettings;

use App\Http\Requests\Management\PaymentSettings\Concerns\ScopesStoreSelection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-11 — the add-bank-account form.
 *
 * Paystack's account-name resolution only accepts NUBAN 10-digit numbers, so
 * the submit button was gated on one; enforce it here too instead of legacy's
 * looser max:20.
 */
class StoreBankAccountRequest extends FormRequest
{
    use ScopesStoreSelection;

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
            'store_id' => ['nullable', 'integer', $this->accessibleStoreRule()],
            'bank_code' => ['required', 'string', 'max:20'],
            'bank_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'digits:10'],
            'account_name' => ['required', 'string', 'max:255'],
        ];
    }
}
