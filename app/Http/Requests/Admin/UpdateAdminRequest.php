<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-10 (admin console) — change an admin's platform role.
 *
 * Only the shape is validated here. The role is deliberately NOT checked with
 * `exists:roles` — a role that really exists but belongs to a business must
 * still reach the controller's platform-role resolution and its deliberate
 * 422 (`errors.role` => "Please select a valid admin role."), never a generic
 * validation message.
 */
class UpdateAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: guard
        // order (403 vs route binding and the 422s) is asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', 'max:255'],
        ];
    }
}
