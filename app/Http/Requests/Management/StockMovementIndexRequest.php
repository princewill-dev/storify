<?php

namespace App\Http\Requests\Management;

use App\Enums\StockMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-29 — filters for the stock-movement ledger.
 *
 * The rule set is a verbatim move from StockMovementController::index().
 *
 * Two things deliberately stay in the controller body, both so their order is
 * unchanged: the `from` > `to` cross-field check (it answers through the
 * ApiController::error envelope, not a validation error bag, and must run
 * after the rules), and the warehouse/store/product access guards (403s that
 * must not move into authorize(), which would run them before the rules).
 *
 * The accepted consequence of this extraction, repo-wide: rules now run during
 * parameter resolution, so a request that is both malformed and unauthorised
 * returns 422 before the controller's guard can answer 403. A valid payload
 * from an unauthorised caller still gets the 403.
 */
final class StockMovementIndexRequest extends FormRequest
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
            'warehouse_id' => ['nullable', 'string', 'max:64'],
            'store_id' => ['nullable', 'string', 'max:64'],
            'stock_location_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(array_map(fn (StockMovementType $type) => $type->value, StockMovementType::cases()))],
            'q' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
