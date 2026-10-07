<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-15 (admin console) — the category directory filter set.
 *
 * The legacy filters, kept: `q` over the name, the status toggle, `per_page`,
 * and the URL-only `store_id` scope (numeric id or public `st_…` id) that the
 * directory resolves against the store table — fail closed, never as a
 * dropped filter.
 */
final class ListCategoriesRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            // The legacy URL-only store scope matches the numeric id or the
            // public `st_…` id.
            'store_id' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
