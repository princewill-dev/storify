<?php

namespace App\Http\Requests\Management\Section;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-36 — the available-products picker filters.
 *
 * The rules are the controller's, moved verbatim.
 */
final class SectionAvailableProductsRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
