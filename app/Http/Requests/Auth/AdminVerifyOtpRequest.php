<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Platform-admin sign-in, step two: consume the code emailed by login().
 *
 * Public — there is no session yet, the code is the credential. The account
 * is resolved through `AdminAuthRepository` so a tenant email cannot take the
 * OTP route into the console; an unknown account, a wrong code and an expired
 * code all collapse into the controller's single 422.
 */
class AdminVerifyOtpRequest extends FormRequest
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
