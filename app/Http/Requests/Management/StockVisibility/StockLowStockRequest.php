<?php

namespace App\Http\Requests\Management\StockVisibility;

use App\Services\StockVisibilityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-29 — the low-stock drill-down filters, moved verbatim from
 * StockVisibilityController::lowStock().
 *
 * `state` is deliberately narrower than the editor's: the drill-down only ever
 * shows the low/out tabs (plus the `attention` union the badges use), never
 * "all" or "in stock". The store/warehouse/product guards stay in the
 * controller body, so a valid payload from a caller outside the business still
 * gets its 403; only a request that is both malformed and unauthorised now
 * answers 422 first (the repo-wide consequence of extracting the rules,
 * deliberately not worked around).
 */
final class StockLowStockRequest extends FormRequest
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
            'store_id' => ['nullable', 'string', 'max:64'],
            'warehouse_id' => ['nullable', 'string', 'max:64'],
            'product_id' => ['nullable', 'integer'],
            'state' => ['nullable', Rule::in([StockVisibilityService::STATE_LOW, StockVisibilityService::STATE_OUT, 'attention'])],
            'q' => ['nullable', 'string', 'max:100'],
            'include_product_fallback' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
