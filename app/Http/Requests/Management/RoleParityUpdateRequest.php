<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * WS-20 — the role edit payload.
 *
 * The rules are the controller's inline set moved verbatim: a partial payload
 * is still accepted (`sometimes`), the rename is unique per business ignoring
 * the role being edited (a rename onto a duplicate used to 500 on the DB
 * index) and permission names are checked with `exists`.
 */
final class RoleParityUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The tenant guard stays in the controller on purpose: a role of
        // another business is a 404 there (invisible), not a validation gate.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Role $role */
        $role = $this->route('role');

        return [
            'name' => [
                'sometimes', 'string', 'max:100',
                Rule::unique('roles', 'name')
                    ->where('business_id', $this->user()?->business_id)
                    ->ignore($role->id),
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ];
    }
}
