<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Complete a management password reset: email, the `business_password_reset`
 * code, and the new password.
 *
 * As with sign-in, an unknown email and a wrong code share one 422 from the
 * controller, so neither can be used to probe which accounts exist.
 */
class ManagementResetPasswordRequest extends FormRequest
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
