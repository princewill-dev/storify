<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-17 — the delivery-route create/edit payload.
 *
 * Create and edit share one contract on purpose: both actions were validated
 * with the same rule set before the extraction (the edit form submits the same
 * fields as create).
 *
 * `fee` is NGN as typed on the form (up to 2 decimal places) and is converted
 * to integer kobo on write. Legacy accepted whole naira only, which is what
 * truncated kobo remainders on edit; `decimal:0,2` refuses more precision than
 * kobo can hold instead of silently rounding it.
 */
final class DeliveryRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard deliberately stays in the controller so its
        // order relative to route binding is unchanged.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'country' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'area' => ['required', 'string', 'max:150'],
            'fee' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
            'delivery_days' => ['required', 'integer', 'min:1', 'max:60'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
