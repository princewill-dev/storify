<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-19 — the suspension reason payload.
 *
 * The reason is required and stored, so the audit trail can answer "why was
 * this customer suspended?" — the legacy API validated it and threw it away,
 * and legacy business writes hid the actor in metadata.user_id instead of
 * using the column. Not to be confused with the base endpoint's optional
 * shape-only reason.
 */
class CustomerParitySuspendRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The tenant guard stays in the controller on purpose: it is a 403 the
        // controller owns ahead of the write, not a validation gate.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
