<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The customer's own profile edit (PUT storefront/account/profile).
 *
 * The rules are the controller's inline set, moved verbatim: a `sometimes`
 * field left out of the payload stays untouched, while every `nullable` field
 * can be explicitly cleared with null.
 *
 * Authorization stays with the route's sanctum_customer middleware and the
 * customer audience token check; the controller had no in-body guard, so there
 * is none to preserve here (the codebase-wide 422-before-403 shift this
 * extraction causes does not apply to an endpoint with no body guard).
 */
final class UpdateProfileRequest extends FormRequest
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
            'first_name' => ['sometimes', 'string', 'max:190'],
            'last_name' => ['nullable', 'string', 'max:190'],
            'phone' => ['nullable', 'string', 'max:50'],
            'street_address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:190'],
            'state' => ['nullable', 'string', 'max:190'],
            'country' => ['nullable', 'string', 'max:190'],
        ];
    }
}
