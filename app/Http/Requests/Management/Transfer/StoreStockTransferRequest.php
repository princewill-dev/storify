<?php

namespace App\Http\Requests\Management\Transfer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-15 — create-transfer validation (draft or directly submitted).
 *
 * `authorize()` carries the account guard the pre-refactor controller ran
 * before it validated anything: a user with no business is refused with the
 * legacy message and a 403, not a 422 field error, so the 403-before-422
 * ordering survives the move. Everything else is the original rule set.
 */
final class StoreStockTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user()?->business_id === null) {
            abort(403, 'A business account is required to create stock transfers.');
        }

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from_location_type' => ['required', Rule::in(['warehouse', 'store'])],
            'from_location_id' => ['required', 'integer'],
            'to_location_type' => ['required', Rule::in(['warehouse', 'store'])],
            'to_location_id' => ['required', 'integer'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct'],
            'items.*.product_variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'submitted' => ['nullable', 'boolean'],
            'status' => ['nullable', Rule::in(['draft', 'pending'])],
        ];
    }
}
