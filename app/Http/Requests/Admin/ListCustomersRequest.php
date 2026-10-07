<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-9 (admin console) — platform customer directory filter validation.
 *
 * The legacy filter set, validated rather than passed through. The sort
 * whitelist is load-bearing: legacy passed `sort_by`/`sort_order` straight to
 * `orderBy` (a SQL injection seam with arbitrary columns in the error path),
 * so a request-supplied column must never survive validation.
 *
 * Inputs are normalised before the rules run: legacy `Customer::STATUS_*` is
 * uppercase in the schema while payloads speak lowercase (matching the
 * management API), and an uppercase legacy-shaped client must still work.
 */
class ListCustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: it must
        // run (403) ahead of the refusals below, and neither belongs in
        // FormRequest::authorize() or middleware.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('status')) {
            $this->merge(['status' => strtolower(trim((string) $this->input('status')))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'deleted'])],
            'country' => ['nullable', 'string', 'max:100'],
            // Legacy handed these straight to orderBy; whitelist only.
            'sort' => ['nullable', Rule::in(['first_name', 'last_name', 'email', 'status', 'orders_count', 'created_at', 'last_login'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
