<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-31 — the category update payload.
 *
 * `parent_id` is refused with a clear 422 until the storefront renders child
 * categories (roadmap D9); store reassignment is deliberately absent — see
 * CategoryParityController's class docblock.
 */
final class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The tenant guard stays in the controller on purpose: it scopes by
        // business AND the deleted-store-filtered store ids below, and its
        // position in the sequence is asserted by the refusals' tests.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
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
