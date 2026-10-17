<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The invoice bank-transfer slip payload
 * (`POST /storefront/{store}/invoices/{token}/bank-transfer`).
 *
 * The same accepted formats and 5 MB cap as the checkout's
 * {@see BankTransferRequest}. The amount is only floored here; the controller
 * checks it against the invoice's remaining balance and the invoice's status
 * before a row is written.
 */
final class InvoiceBankTransferRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0.01'],
            'store_bank_id' => ['nullable', 'integer'],
            'payment_slip' => ['nullable', 'file', 'mimes:jpeg,png,jpg,heic,pdf', 'max:5120'],
        ];
    }
}
