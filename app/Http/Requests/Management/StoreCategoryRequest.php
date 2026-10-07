<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-31 — the category create payload.
 *
 * `parent_id` is refused with a clear 422 until the storefront renders child
 * categories: the field was accepted and returned with no ownership or cycle
 * validation while no UI ever set it (roadmap D9).
 */
final class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The tenant guard stays in the controller on purpose: a foreign or
        // deleted store is the controller's "Invalid store selection." 422,
        // not a validation failure with different copy.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'store_id' => ['required', 'integer'],
            // Optional because the legacy create form omitted the field its
            // own controller required — every submission from that page
            // bounced with "The status field is required." Active stays the
            // default so the modern modal never dead-ends on a hidden field.
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'parent_id' => ['nullable', 'prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.prohibited' => 'Category hierarchy is not supported yet.',
        ];
    }
}
