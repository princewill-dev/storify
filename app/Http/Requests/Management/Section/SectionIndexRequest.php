<?php

namespace App\Http\Requests\Management\Section;

use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-36 — the sections list filters.
 *
 * The rules are the controller's, moved verbatim; `status` filters the
 * section's own status (the show endpoint's `status` filters products
 * instead, so the two contracts live in separate classes).
 */
final class SectionIndexRequest extends FormRequest
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
            'status' => ['nullable', Rule::in([Section::STATUS_ACTIVE, Section::STATUS_INACTIVE])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
