<?php

namespace App\Http\Requests\Management\Section;

use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-36 — the section picker filters.
 *
 * The rules are the controller's, moved verbatim. `warehouse_id` stays a
 * plain integer rule: the controller re-checks it against the caller's
 * accessible warehouses and answers its own 403, and an `exists:` rule would
 * both admit warehouses the caller cannot reach and change that refusal's
 * shape.
 */
final class SectionPickerRequest extends FormRequest
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
            'warehouse_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([Section::STATUS_ACTIVE, Section::STATUS_INACTIVE])],
        ];
    }
}
