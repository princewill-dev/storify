<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Change the signed-in account's password.
 *
 * A forced change (first sign-in after an invitation) has no known current
 * password to prove, so only the new one is collected; every other change
 * must submit `current_password`, which the controller then verifies with
 * `Hash::check` — the wrong-password refusal is a 422 body carrying the
 * `current_password` error key, not a validation rule.
 */
class ManagementChangePasswordRequest extends FormRequest
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
        $rules = ['password' => ['required', 'string', 'min:8', 'confirmed']];

        if (! (bool) $this->user()?->force_password_change) {
            $rules['current_password'] = ['required', 'string'];
        }

        return $rules;
    }
}
