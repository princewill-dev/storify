<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-15 (admin console) — `POST /api/v1/admin/categories` payload.
 *
 * `store_id` stays a plain integer rule: the controller re-checks it against
 * live (not deleted) stores and answers the legacy "Choose a store that still
 * exists." 422 itself. An `exists:stores,id` rule would both admit deleted
 * stores and turn that refusal into a framework rule failure with different
 * copy.
 */
final class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: guard
        // order (403 before the refusals below) is asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'store_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
