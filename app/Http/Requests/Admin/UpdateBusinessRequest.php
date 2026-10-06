<?php

namespace App\Http\Requests\Admin;

use App\Models\Business;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-4 (admin console) — owner/business edit from the list or the console.
 *
 * The `status` select accepts `active|pending|suspended` only — `deleted` is
 * reachable exclusively through the delete endpoint, which runs the guards
 * (legacy allowed an edit to set `deleted` on the owner, silently bypassing
 * all three).
 */
class UpdateBusinessRequest extends FormRequest
{
    /**
     * Owner account statuses an edit is allowed to reach. `deleted` is
     * excluded on purpose — deletion has guards, an edit does not.
     */
    private const EDITABLE_STATUSES = ['active', 'pending', 'suspended'];

    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: guard
        // order (403 vs route binding and the 422s) is asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Business|null $business */
        $business = $this->route('business');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'owner_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($business?->user_id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', Rule::in(self::EDITABLE_STATUSES)],
            'business_type_id' => ['nullable', 'integer', 'exists:business_types,id'],
            'ownership_type_id' => ['nullable', 'integer', 'exists:ownership_types,id'],
        ];
    }
}
