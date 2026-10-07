<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Platform-admin sign-in, step one: email + password.
 *
 * Shape only. The role scope that keeps tenant accounts out of the console
 * lives in `AdminAuthRepository::findPlatformAccountByEmail`, not in a
 * validation rule — the refusal for "no such platform account" and "wrong
 * password" is deliberately the same generic 422 from the controller.
 */
class AdminLoginRequest extends FormRequest
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
            'password' => ['required', 'string'],
        ];
    }
}
