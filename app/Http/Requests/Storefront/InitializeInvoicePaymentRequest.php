<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The invoice payment initialization payload
 * (`POST /storefront/{store}/invoices/{token}/payments/{provider}/initialize`).
 *
 * The same two optional fields checkout takes. An absent amount means "the
 * outstanding balance"; the controller clamps and refuses against the invoice's
 * remaining balance before the gateway is touched.
 */
final class InitializeInvoicePaymentRequest extends FormRequest
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
            'callback_url' => ['nullable', 'url', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ];
    }
}
