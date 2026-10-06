<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-4 (admin console) — directory filter validation.
 *
 * The legacy filter set the audit flagged as missing over the previous
 * endpoint: created-date range, the `deleted` option (deleted rows are
 * hidden by default, like the legacy list) and a sort whitelist so a
 * request-supplied column never reaches orderBy.
 */
class ListBusinessesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: guard
        // order (403 vs route binding and the 422s) is asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'pending', 'suspended', 'deleted'])],
            'include_deleted' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['name', 'business_code', 'status', 'created_at', 'stores_count', 'warehouses_count', 'users_count'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
