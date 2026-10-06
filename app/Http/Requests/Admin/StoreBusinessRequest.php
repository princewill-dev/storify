<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-4 (admin console) — admin-provisioned business + owner account.
 *
 * The owner email is the login identity, so it is unique and never ignored
 * (there is no existing owner at create time). Validation messages are the
 * framework defaults; the audit tests assert the field names.
 */
class StoreBusinessRequest extends FormRequest
{
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
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', Rule::in(['active', 'pending'])],
            'business_type_id' => ['nullable', 'integer', 'exists:business_types,id'],
            'ownership_type_id' => ['nullable', 'integer', 'exists:ownership_types,id'],
        ];
    }
}
