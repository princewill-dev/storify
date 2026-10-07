<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Storefront password reset (POST storefront/auth/reset-password).
 *
 * Verifying the `customer_password_reset` OTP, hashing and the token
 * revocation stay in the controller — a failed verification is the same 422
 * whether the email exists or not.
 */
final class CustomerResetPasswordRequest extends FormRequest
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
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
