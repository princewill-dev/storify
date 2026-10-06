<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

/**
 * WS-15 (admin console) — the product list filter set.
 *
 * The legacy list's filters, kept: `q` over name / code / store / category,
 * status, created range, `per_page` 10/50/100, and the URL-only `store_id`
 * scope (numeric id or public `st_…` id). `sort`/`direction` are the SPA's
 * table-header whitelist — the legacy list passed no sort through.
 */
final class ListProductsRequest extends ProductApiRequest
{
    /**
     * Columns the list may sort on — the legacy list passed no sort through,
     * but the SPA's table headers need a whitelist either way.
     */
    public const SORTABLE = ['name', 'product_code', 'amount', 'status', 'featured', 'created_at', 'updated_at'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            // The legacy URL-only store scope matches the numeric id or the
            // public `st_…` id.
            'store_id' => ['nullable', 'string', 'max:50'],
            'category_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
