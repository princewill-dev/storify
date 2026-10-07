<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Paystack verification payload (`POST /storefront/{store}/payments/paystack/verify`).
 *
 * The rules are the controller's inline set, moved verbatim: the reference the
 * lookup is scoped by, and nothing else.
 *
 * Validation now runs during parameter resolution, before the controller's
 * store lookup — the accepted codebase-wide consequence of extracting
 * validation: a malformed payload aimed at an unknown store answers 422 where
 * it used to answer the store's 404. A valid payload still 404s (or 402s)
 * exactly as before.
 */
final class VerifyPaymentRequest extends FormRequest
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
            'reference' => ['required', 'string'],
        ];
    }
}
