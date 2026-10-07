<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The bank-transfer slip payload (`POST /storefront/{store}/payments/bank-transfer`).
 *
 * The rules are the controller's inline set, moved verbatim, including the
 * accepted slip formats and the 5 MB cap. The amount is only floored here;
 * the controller still clamps it to the order's remaining balance before it
 * decides whether the order is already fully paid (409).
 *
 * Validation now runs during parameter resolution, before the controller's
 * store and order lookups — the accepted codebase-wide consequence of
 * extracting validation: a malformed payload aimed at an unknown store
 * answers 422 where it used to answer the store's 404. A valid payload still
 * 404s or 409s exactly as before.
 */
final class BankTransferRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:0.01'],
            'store_bank_id' => ['nullable', 'integer'],
            'payment_slip' => ['nullable', 'file', 'mimes:jpeg,png,jpg,heic,pdf', 'max:5120'],
        ];
    }
}
