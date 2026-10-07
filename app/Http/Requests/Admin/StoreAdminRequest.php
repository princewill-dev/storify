<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-10 (admin console) — invite a platform admin.
 *
 * Email uniqueness is checked across `users` **and** `customers` (the legacy
 * cross-check the new stack never had): a customer address cannot be
 * re-registered as a platform admin. `exists:roles,name` is only the shape
 * check — whether the role is *assignable* (global, `web` guard, not Super
 * Admin) is resolved in the controller, which answers the deliberate 422 with
 * `errors.role` rather than a generic validation message.
 */
class StoreAdminRequest extends FormRequest
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
            'email' => ['required', 'email', 'max:255', 'unique:users,email', 'unique:customers,email'],
            'role' => ['required', 'string', 'max:255', Rule::exists('roles', 'name')],
        ];
    }
}
