<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Paystack initialization payload (`POST /storefront/{store}/payments/paystack/initialize`).
 *
 * The rules are the controller's inline set, moved verbatim. The state guards
 * that follow them stay in the controller: an order with nothing left to pay
 * answers 409 and a non-positive amount answers 422 before the gateway is
 * ever touched.
 *
 * Validation now runs during parameter resolution, before the controller's
 * store lookup — the accepted codebase-wide consequence of extracting
 * validation: a malformed payload aimed at an unknown store answers 422 where
 * it used to answer the store's 404. A valid payload still 404s or 409s
 * exactly as before.
 */
final class InitializePaymentRequest extends FormRequest
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
            'order_number' => ['required', 'string'],
            'callback_url' => ['nullable', 'url', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
        ];
    }
}
