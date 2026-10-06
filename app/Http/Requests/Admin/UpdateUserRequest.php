<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-8 (admin console) — edit-user validation.
 *
 * Legacy required name and email — the previous API marked them `sometimes`,
 * so a phone-only payload succeeded silently and (worse) an empty body
 * returned "User updated.". The unique email check ignores the row being
 * edited.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: it must
        // run (403) ahead of the managed-role 404, and neither belongs in
        // FormRequest::authorize() or middleware.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user')?->id)],
            'phone' => ['nullable', 'string', 'max:50'],
        ];
    }
}
