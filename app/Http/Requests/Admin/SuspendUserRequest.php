<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-8 (admin console) — suspension reason validation.
 *
 * The reason is required again: the previous API accepted none, while the
 * legacy console's suspend modal required it (max 2000).
 */
class SuspendUserRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
