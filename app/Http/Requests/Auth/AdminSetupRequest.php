<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Platform bootstrap — the one-time superadmin account.
 *
 * Public on purpose: the endpoint only means anything while no superadmin
 * exists, and that 409 guard deliberately stays in the controller (its order
 * is asserted behaviour). Because rules run during parameter resolution, an
 * already-set-up request with a malformed payload now earns 422 before the
 * 409 — the accepted, codebase-wide consequence of FormRequest extraction; a
 * valid payload still meets the guard first.
 */
class AdminSetupRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }
}
