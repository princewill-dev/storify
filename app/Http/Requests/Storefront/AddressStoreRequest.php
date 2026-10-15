<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A new address in the customer's own address book
 * (`POST /api/v1/storefront/account/addresses`).
 *
 * The recipient block is required because these are delivery addresses, not
 * profile fields: the person receiving the parcel is often not the account
 * holder, which is why `recipient_name` and `recipient_phone` are separate
 * columns with no fallback to the customer's own name.
 *
 * `is_default` is optional here. The controller makes the first address a
 * customer saves their default regardless — a book whose only entry is not the
 * default would leave checkout with nothing preselected.
 *
 * Authorization is the route's: `auth:sanctum_customer`, the customer audience
 * check and `customer.business`, which is what guarantees a business to scope
 * the write to. The customer_id this row lands on is the token's, never the
 * payload's, so there is no ownership rule to validate here.
 */
final class AddressStoreRequest extends FormRequest
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
