<?php

namespace App\Http\Requests\Management\Role;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The base RoleController's create payload — the rules that controller
 * carried inline, verbatim.
 *
 * Deliberately separate from the WS-20 slice that replaces these URIs on the
 * shared routes (RoleParityController, registered by
 * routes/api/v1/management/ws20-staff-roles.php): that one guards `name` with
 * a per-business `unique` rule and each permission with `exists`, which turns
 * both of the base slice's own answers — the 422 envelope message for a
 * duplicate name, Spatie's exception for a bogus permission — into validation
 * errors. This refactor must not change what the base controller does, so the
 * two rule sets are not interchangeable and are kept apart.
 */
final class StoreRoleRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string'],
        ];
    }
}
