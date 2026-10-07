<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-16 (admin console) — `POST /api/v1/admin/support-messages/{id}/reply`.
 *
 * Only the reply body is validated here. The closed / already-replied
 * refusals are thread-state transitions, not input shape: they keep their own
 * 422 copy in the controller and are asserted by the inbox tests, so they
 * stay out of these rules.
 */
final class ReplySupportMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: guard
        // order (403 before the refusals below) is asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reply' => ['required', 'string', 'max:2000'],
        ];
    }
}
