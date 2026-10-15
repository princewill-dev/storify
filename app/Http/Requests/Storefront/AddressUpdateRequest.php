<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An edit to a saved address
 * (`PUT /api/v1/storefront/account/addresses/{address}`).
 *
 * A replace rather than a patch — the same required set as
 * AddressStoreRequest, because the storefront edit form submits the whole
 * address and a partial payload would silently blank nothing but leave the
 * address half-old. `is_default` stays optional: leaving it out preserves the
 * row's current default status instead of clearing it.
 *
 * Ownership is not a rule here. A `{address}` belonging to another customer is
 * refused by the controller as a 404, so another customer's address is
 * indistinguishable from one that does not exist.
 */
final class AddressUpdateRequest extends FormRequest
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
            'label' => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:190'],
            'recipient_phone' => ['required', 'string', 'max:50'],
            'company_name' => ['nullable', 'string', 'max:190'],
            'street_address' => ['required', 'string', 'max:255'],
            'apartment' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:190'],
            'state' => ['required', 'string', 'max:190'],
            'country' => ['nullable', 'string', 'max:190'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'zip_code' => ['nullable', 'string', 'max:20'],
            'map_link' => ['nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
