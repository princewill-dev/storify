<?php

namespace App\Http\Requests\Management\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The base CategoryController's update payload — the rules that controller
 * carried inline, verbatim.
 *
 * Deliberately separate from App\Http\Requests\Management\UpdateCategoryRequest
 * (the WS-31 slice behind the shared routes, served by CategoryParityController):
 * that one prohibits `parent_id`, while the base update accepted it, and this
 * refactor must not change what the base controller does. The two are not
 * interchangeable.
 *
 * The category guard stays in the controller body on purpose: it is a
 * business+store check whose 403 has its own place in the refusal order, not a
 * FormRequest::authorize() concern.
 */
class UpdateCategoryRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ];
    }
}
