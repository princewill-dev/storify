<?php

namespace App\Http\Requests\Management;

use App\Repositories\Management\ProductListRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-25 — the products list filters.
 *
 * The superset of the WS-14 ProductIndexRequest rules this surface serves:
 * the section/has-variant filters, the created-at range legacy accepted
 * server-side but never exposed, and the sort whitelist a bigger catalog
 * needs.
 *
 * `digital_only`, `has_variants` and `low_stock` are validated here with the
 * same nullable/boolean rules the controller used; the repository reads them
 * through the request helpers, not the raw validated value, so "absent" and
 * "present but false" stay distinguishable.
 *
 * The sort whitelist comes from ProductListRepository::SORTS — the same map
 * the repository orders by — so a sort can never validate here and be unknown
 * there.
 */
final class ProductListIndexRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'store_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'section_id' => ['nullable', 'integer'],
            'digital_only' => ['nullable', 'boolean'],
            'has_variants' => ['nullable', 'boolean'],
            'low_stock' => ['nullable', 'boolean'],
            // Legacy accepted a created-at range server-side (no UI); the new
            // list surfaces it. `to` is inclusive of the whole day.
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'sort' => ['nullable', Rule::in(array_keys(ProductListRepository::SORTS))],
            // The legacy per-page whitelist (10/50/100) is what the UI offers;
            // any 1–100 page size a sibling screen already sends still works.
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
