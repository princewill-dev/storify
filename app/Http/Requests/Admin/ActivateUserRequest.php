<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-8 (admin console) — activation reason validation.
 *
 * Legacy did not build an activation reason form — it submitted a hidden,
 * hardcoded reason — so the field stays optional and
 * UserModerationService::DEFAULT_ACTIVATION_REASON restores parity.
 */
class ActivateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: it must
        // run (403) ahead of the managed-role 404, and neither belongs in
        // FormRequest::authorize() or middleware.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
