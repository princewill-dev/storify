<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Platform-admin password reset: the emailed code plus the replacement
 * password.
 *
 * Public — no session exists yet; the code is the credential. The account is
 * resolved through `AdminAuthRepository` (platform roles only) and the code is
 * consumed inside `AdminAuthService::resetPassword`; a bad code or unknown
 * account is the controller's single 422.
 */
class AdminResetPasswordRequest extends FormRequest
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
            'otp' => ['required', 'digits:6'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
