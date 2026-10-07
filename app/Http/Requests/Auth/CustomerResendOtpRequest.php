<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Storefront OTP re-send (POST storefront/auth/resend-otp).
 *
 * The response is always the same whether or not the account exists, so the
 * existence check stays in the controller.
 */
final class CustomerResendOtpRequest extends FormRequest
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
