<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-8 (admin console) — stop-impersonation validation.
 *
 * The admin may close one specific open session (`impersonation_id`) or, with
 * no id, the user's latest live session — the same lookup the detail console's
 * "active impersonation" block shows.
 */
class StopImpersonationRequest extends FormRequest
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
            'impersonation_id' => ['nullable', 'integer', 'exists:impersonations,id'],
        ];
    }
}
