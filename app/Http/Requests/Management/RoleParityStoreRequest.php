<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-20 — the role create payload.
 *
 * The rules are the controller's inline set moved verbatim: permission names
 * are checked with `exists` (a bogus name used to 500 inside Spatie) and the
 * role name is unique per business, so a duplicate comes back as a 422 on the
 * field instead of a raw 500 from the (business, name, guard) index.
 */
final class RoleParityStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Access is the route's `permission:staff create` middleware, not a
        // validation gate; the unique rule scopes itself by the caller's
        // business below.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('roles', 'name')->where('business_id', $this->user()?->business_id),
            ],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }
}
