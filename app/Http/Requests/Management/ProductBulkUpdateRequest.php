<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-25 — the bulk edit payload.
 *
 * Each row carries its own optional amount/quantity/stock_quantity/status;
 * blank fields leave the product as it is, exactly as the legacy modal left
 * untouched inputs alone. The rules are the controller's, moved verbatim.
 */
final class ProductBulkUpdateRequest extends FormRequest
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
            'products' => ['required', 'array', 'min:1'],
            'products.*.id' => ['required', 'integer'],
            'products.*.amount' => ['nullable', 'numeric', 'gt:0'],
            'products.*.quantity' => ['nullable', 'integer', 'min:0'],
            'products.*.stock_quantity' => ['nullable', 'integer', 'min:0'],
            'products.*.status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }
}
