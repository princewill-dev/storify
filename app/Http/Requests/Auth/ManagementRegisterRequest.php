<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Management-app registration: the business-owner account payload.
 *
 * The email is checked against both the `users` and the `customers` tables —
 * one address may not exist on either side of the platform. A concurrent
 * insert can still race these rules; the unique-key collision that results is
 * caught in `ManagementAuthService::register()`, which the controller maps to
 * its 422.
 */
class ManagementRegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email', 'unique:customers,email'],
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
