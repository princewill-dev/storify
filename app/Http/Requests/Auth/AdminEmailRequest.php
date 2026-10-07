<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The email-only payload shared by `resend-otp` and `forgot-password` — the
 * two public admin endpoints that dispatch a code but never consume one.
 *
 * One class because the rule set is identical, matching the shared-request
 * pattern the admin console already uses (`BusinessReasonRequest`). Both
 * endpoints answer with the same "if the account exists" envelope whether or
 * not the address resolves, so the rule is email shape only.
 */
class AdminEmailRequest extends FormRequest
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
            'email' => ['required', 'email'],
        ];
    }
}
