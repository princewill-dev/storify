<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-9 (admin console) — customer suspension reason validation.
 *
 * The reason is required and persisted (legacy's own console required it and
 * wrote it to the audit row; the email also carries it).
 */
class SuspendCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: it must
        // run (403) ahead of the refusals below, and neither belongs in
        // FormRequest::authorize() or middleware.
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
