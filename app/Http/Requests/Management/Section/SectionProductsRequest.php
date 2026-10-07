<?php

namespace App\Http\Requests\Management\Section;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-36 — the product-list filters the section detail embeds.
 *
 * The rules are the controller's, moved verbatim. `status` filters the
 * section's *products*, not the section itself — which is why this is a
 * separate contract from SectionIndexRequest even though the allowed values
 * coincide today.
 */
final class SectionProductsRequest extends FormRequest
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
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
