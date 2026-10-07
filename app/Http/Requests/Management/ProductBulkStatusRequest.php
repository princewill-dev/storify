<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-25 — the bulk activate/deactivate payload.
 *
 * The rules are the controller's, moved verbatim.
 */
final class ProductBulkStatusRequest extends FormRequest
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
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
