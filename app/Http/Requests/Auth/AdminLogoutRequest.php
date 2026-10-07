<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `logout` payload: an optional refresh token.
 *
 * The access token is the credential and the route is already behind
 * `auth:sanctum` + the admin audience, so nothing is authorized here. When
 * the client sends its refresh token too the controller revokes it —
 * otherwise a signed-out device would keep a usable refresh token.
 */
class AdminLogoutRequest extends FormRequest
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
            'refresh_token' => ['nullable', 'string'],
        ];
    }
}
