<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-18 (admin console) — company-services list filters.
 *
 * The status list is the write contract's own (`CompanyServiceWriteRequest`),
 * so a filter value can never drift from a value the rows can hold; the sort
 * whitelist is closed here because the repository passes `sort` straight to
 * `orderBy` and an unknown column must be a 422, never a query fragment.
 */
final class ListCompanyServicesRequest extends FormRequest
{
    /**
     * Whitelisted sort columns.
     */
    private const SORTS = ['order', 'title', 'created_at', 'updated_at', 'id'];

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
            'status' => ['nullable', Rule::in(CompanyServiceWriteRequest::STATUSES)],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
