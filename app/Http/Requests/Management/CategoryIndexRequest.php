<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-31 — the categories list filters.
 *
 * The store filter is the internal `stores.id` — the SPA sends
 * `auth.stores[].id`. Legacy accepted the public `store_id` code behind this
 * same parameter name; the verify pass calls the divergence out, so the
 * internal id is now the documented convention (as products/orders already
 * use).
 */
final class CategoryIndexRequest extends FormRequest
{
    /** The per-page sizes the list UI offers (same whitelist as the products list). */
    public const PER_PAGE_OPTIONS = [10, 20, 50, 100];

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
            'store_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE_OPTIONS)],
        ];
    }
}
