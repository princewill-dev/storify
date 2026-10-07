<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Management-app sign-in, step one: email + password.
 *
 * Shape only. There is deliberately no role scope in the payload: the
 * controller's own branch decides what each account type gets — staff sign in
 * directly, owners/admins continue to an OTP challenge, and every other role
 * is refused with the same generic "not a business account" 403 that a wrong
 * password never reaches.
 */
class ManagementLoginRequest extends FormRequest
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
