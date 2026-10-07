<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Storefront sign-out (POST storefront/auth/logout).
 *
 * Authentication is enforced by the route middleware (auth:sanctum_customer),
 * not here; the refresh token is optional and is revoked by the controller
 * when supplied.
 */
final class CustomerLogoutRequest extends FormRequest
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
            'refresh_token' => ['nullable', 'string'],
        ];
    }
}
