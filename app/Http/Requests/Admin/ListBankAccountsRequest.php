<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-12 — the bank-account console list filters.
 *
 * The status filter only ever offered `active` / `inactive`; the legacy screen
 * had no "all statuses incl. deleted" concept because these rows are never
 * soft-deleted.
 */
final class ListBankAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard deliberately stays in the controller so its
        // order relative to route binding is unchanged.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
