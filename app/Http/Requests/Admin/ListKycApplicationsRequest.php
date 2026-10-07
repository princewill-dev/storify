<?php

namespace App\Http\Requests\Admin;

use App\Models\KycApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-3 (admin console) — KYC queue filter validation.
 *
 * The status list the queue pills offer (`all` is the explicit opt-out from
 * the submitted default), the free-text search and the per-page ceiling. The
 * platform-admin guard stays in the controller on purpose: it must run (403)
 * ahead of any read, and neither it nor the guard's order belongs in
 * FormRequest::authorize() or middleware.
 */
class ListKycApplicationsRequest extends FormRequest
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
            'status' => ['nullable', Rule::in([
                KycApplication::STATUS_DRAFT,
                KycApplication::STATUS_SUBMITTED,
                KycApplication::STATUS_APPROVED,
                KycApplication::STATUS_REJECTED,
                'all',
            ])],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
