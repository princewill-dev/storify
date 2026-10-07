<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Start a management password reset: the account email only.
 *
 * The response is the same generic "if that email is registered" line whether
 * or not it matched, so this payload is not allowed to leak more than that.
 */
class ManagementForgotPasswordRequest extends FormRequest
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
        ];
    }
}
