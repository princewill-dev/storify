<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Resend a management sign-in code: email + the optional context the client
 * wants. When the context is omitted the controller derives it from the
 * account's verification state (verified accounts get a `business_login`
 * code, unverified ones a `business_email_verification` code).
 */
class ManagementResendOtpRequest extends FormRequest
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
            'context' => ['nullable', 'in:business_login,business_email_verification'],
        ];
    }
}
