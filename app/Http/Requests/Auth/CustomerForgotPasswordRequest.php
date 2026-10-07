<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Storefront password-reset request (POST storefront/auth/forgot-password).
 *
 * Same enumeration-safe shape as resend-otp: the controller only sends when
 * the customer exists but always reports the same message.
 */
final class CustomerForgotPasswordRequest extends FormRequest
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
        ];
    }
}
