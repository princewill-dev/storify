<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-15 (admin console) — `PUT /api/v1/admin/categories/{category}` payload.
 *
 * The edit form submits the full record (all three fields required), so the
 * rules match the create payload. Two deliberate absences:
 *
 * - no `slug`: it is server-generated, and stable across edits unless the
 *   name changes (the legacy `categoryNameChanged()` rule, held in
 *   CategoryService);
 * - no `exists:` on `store_id`: the controller's live-store check answers the
 *   "Choose a store that still exists." 422, and legacy's defect was accepting
 *   a store the category did not belong to — that re-link is now written to
 *   `store_id`/`business_id` together.
 */
final class UpdateCategoryRequest extends FormRequest
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
