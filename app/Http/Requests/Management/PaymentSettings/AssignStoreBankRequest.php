<?php

namespace App\Http\Requests\Management\PaymentSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-11 — assigning one of the business's bank accounts to a store.
 */
class AssignStoreBankRequest extends FormRequest
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
            'store_bank_id' => ['required', 'integer'],
        ];
    }
}
