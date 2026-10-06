<?php

namespace App\Http\Requests\Management\Transfer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-15 — the roadmap's `stock-locations` read. `location_type`/`location_id`
 * and `source_type`/`source_id` are the same pair under two names; the
 * cross-required rules mean a lone half of either pair is a 422 field error,
 * while passing neither is left to the controller's explicit pair-required
 * message (exact pre-refactor behaviour).
 */
final class StockLocationsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'location_type' => ['nullable', Rule::in(['warehouse', 'store']), 'required_with:location_id'],
            'location_id' => ['nullable', 'integer', 'required_with:location_type'],
            'source_type' => ['nullable', Rule::in(['warehouse', 'store']), 'required_with:source_id'],
            'source_id' => ['nullable', 'integer', 'required_with:source_type'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
