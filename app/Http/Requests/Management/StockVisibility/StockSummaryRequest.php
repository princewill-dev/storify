<?php

namespace App\Http\Requests\Management\StockVisibility;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-29 — the dashboard stock-summary filters, moved verbatim from
 * StockVisibilityController::summary().
 *
 * The summary endpoint validated before it ran any guard, so nothing here
 * reorders a refusal: an invalid product_limit still answers 422 before the
 * store/warehouse access checks (which resolve only the filters that pass
 * validation) run in the controller body.
 */
final class StockSummaryRequest extends FormRequest
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
            'product_limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }
}
