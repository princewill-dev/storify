<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-3 (admin console) — approve's review note validation.
 *
 * Optional (max 2000), as legacy. The status guard that refuses anything not
 * `submitted` — and the row lock around it — stays in KycApprovalService,
 * inside the transition, so a direct POST still cannot re-approve.
 */
class ApproveKycApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: it must
        // run (403) before the transition, and neither it nor its ordering
        // belongs in FormRequest::authorize() or middleware.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
