<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Management-app OTP sign-in, step two: the emailed six-digit code.
 *
 * The code's context — `business_login` or `business_email_verification` — is
 * not chosen by the client; `ManagementAuthService::completeLogin()` checks
 * both in their original order and reports which one matched.
 */
class ManagementVerifyOtpRequest extends FormRequest
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
        ];
    }
}
