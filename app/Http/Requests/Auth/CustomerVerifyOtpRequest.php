<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Storefront OTP verification (POST storefront/auth/verify-otp).
 *
 * A wrong code and an unknown email return the same 422 message, so the
 * lookup + OtpService::verify check stays in the controller as one branch.
 */
final class CustomerVerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'otp' => ['required', 'digits:6'],
        ];
    }
}
