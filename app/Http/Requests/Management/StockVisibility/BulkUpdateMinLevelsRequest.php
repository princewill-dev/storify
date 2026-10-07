<?php

namespace App\Http\Requests\Management\StockVisibility;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-29 — the min-level editor's grid save payload, moved verbatim from
 * StockVisibilityController::bulkUpdateMinLevels().
 *
 * The endpoint validated first in the pre-refactor body too, so nothing here
 * changes a refusal order (the all-or-nothing access check for every id still
 * runs in the controller body after these rules). `distinct` is what lets the
 * controller treat the id list as a set when it verifies every id before
 * writing a row.
 */
final class BulkUpdateMinLevelsRequest extends FormRequest
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
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.min_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
