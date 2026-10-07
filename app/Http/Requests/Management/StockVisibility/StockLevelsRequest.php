<?php

namespace App\Http\Requests\Management\StockVisibility;

use App\Services\StockVisibilityService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-29 — the min-level editor's list filters, moved verbatim from
 * StockVisibilityController::levels().
 *
 * This is the cross-location read (WS-15's `stock-locations` read is scoped to
 * one location and feeds the transfer grid), so unlike the drill-down it also
 * accepts the `in_stock` / `all` states and an explicit sort. The
 * store/warehouse/product guards stay in the controller body.
 */
final class StockLevelsRequest extends FormRequest
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
            'state' => ['nullable', Rule::in([StockVisibilityService::STATE_LOW, StockVisibilityService::STATE_OUT, StockVisibilityService::STATE_OK, 'all'])],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['attention', 'quantity_asc', 'quantity_desc', 'newest'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
