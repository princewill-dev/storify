<?php

namespace App\Http\Requests\Management\Transfer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-15 — the create grid's source-location filters (`transfer/source-products`).
 *
 * The location pair is required here; the controller still resolves it through
 * the user's accessible locations and reports an unreachable id as a 422
 * "invalid selection" rather than a 403.
 */
final class SourceProductsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'location_type' => ['required', Rule::in(['warehouse', 'store'])],
            'location_id' => ['required', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
