<?php

namespace App\Http\Requests\Management\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The base CategoryController's create payload — the rules that controller
 * carried inline, verbatim.
 *
 * Deliberately separate from App\Http\Requests\Management\StoreCategoryRequest
 * (the WS-31 slice behind the shared routes, served by CategoryParityController):
 * that one prohibits `parent_id`, while the base create accepted it, and this
 * refactor must not change what the base controller does. The two are not
 * interchangeable.
 *
 * `store_id` stays a plain integer rule: the controller re-checks it against
 * the caller's accessible stores and answers its own 422 "Invalid store
 * selection." An `exists:` rule would both admit stores the caller cannot
 * reach and turn the deliberate anti-id-probing refusal (a foreign or deleted
 * store must not read as "exists but forbidden") into a framework rule
 * failure with different copy.
 */
class StoreCategoryRequest extends FormRequest
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
            'store_id' => ['required', 'integer'],
            'parent_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ];
    }
}
