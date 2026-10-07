<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Storefront registration (POST storefront/auth/register).
 *
 * The email must be free on both the users and customers tables — one address
 * cannot belong to a business account and a shopper. The unique rules can
 * still lose a race to a concurrent insert; the controller maps that
 * duplicate-key QueryException to the same 422 message.
 */
final class CustomerRegisterRequest extends FormRequest
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
            'first_name' => ['required', 'string', 'max:190'],
            'last_name' => ['nullable', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email', 'unique:customers,email'],
            'phone' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
