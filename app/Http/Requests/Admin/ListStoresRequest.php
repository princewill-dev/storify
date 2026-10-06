<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-6 (admin console) — directory filter validation.
 *
 * The legacy filter set the audit flagged as missing over the previous
 * endpoint: created-date range, the working `deleted` option, `q` over store
 * id and owner (legacy's placeholder promised those and only name worked),
 * plus ownership/business type and the main-store badge filter.
 *
 * The sort whitelist is load-bearing: the ordering column is passed straight
 * into `orderBy`, so a request-supplied column must never survive validation.
 */
class ListStoresRequest extends FormRequest
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
            'status' => ['nullable', Rule::in(['active', 'inactive', 'suspended', 'pending', 'deleted'])],
            'include_deleted' => ['nullable', 'boolean'],
            'is_main' => ['nullable', 'boolean'],
            'ownership_type_id' => ['nullable', 'integer', 'exists:ownership_types,id'],
            'business_type_id' => ['nullable', 'integer', 'exists:business_types,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(['name', 'store_id', 'status', 'balance', 'created_at', 'products_count', 'orders_count'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
