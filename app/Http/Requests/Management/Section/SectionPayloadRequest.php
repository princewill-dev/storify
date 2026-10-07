<?php

namespace App\Http\Requests\Management\Section;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-36 — the section create/edit payload.
 *
 * Create and edit share one contract on purpose: both actions were validated
 * with the same rule set before the extraction (the controller carried them
 * in one private `validated()` method), and the edit form submits the same
 * fields as create.
 */
final class SectionPayloadRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
