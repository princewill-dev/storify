<?php

namespace App\Http\Requests\Admin;

use App\Enums\WarehouseStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * AD-14 — the platform warehouse directory filters.
 *
 * Extracted from the controller unchanged: the three-value status whitelist
 * (the deleted status must stay selectable — legacy's `Deleted` option only
 * worked through this filter), the name/code/business search term, the page
 * size and the sort whitelist. The sort column is passed straight into
 * `orderBy`, so a request-supplied column must never survive validation.
 */
final class ListWarehousesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: it must
        // not move into middleware or FormRequest::authorize(), and tests
        // assert its order against route-binding 404s.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in([
                WarehouseStatus::ACTIVE->value,
                WarehouseStatus::INACTIVE->value,
                WarehouseStatus::DELETED->value,
            ])],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Sort whitelisted, never taken straight from the request.
            'sort' => ['nullable', Rule::in(['name', 'warehouse_code', 'status', 'created_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
