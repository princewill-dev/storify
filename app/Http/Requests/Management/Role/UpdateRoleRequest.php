<?php

namespace App\Http\Requests\Management\Role;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The base RoleController's update payload — the rules that controller
 * carried inline, verbatim.
 *
 * Every rule stays `sometimes`: the controller writes `name` and `permissions`
 * only when the key is present, so an empty body remains a successful no-op.
 *
 * Deliberately separate from the WS-20 slice that replaces these URIs on the
 * shared routes (RoleParityController, registered by
 * routes/api/v1/management/ws20-staff-roles.php): that one adds the
 * per-business `unique` check on rename and `exists` on each permission, and
 * refuses renames of protected system roles. This refactor must not change
 * what the base controller does, so the two rule sets are not interchangeable.
 *
 * The role guard stays in the controller body on purpose: it is a
 * business-scope check whose 403 must keep its place in the refusal order,
 * not a FormRequest::authorize() concern.
 */
final class UpdateRoleRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:100'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ];
    }
}
