<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The staff edit payload — StaffController::update()'s inline rules moved
 * verbatim.
 *
 * One ordering note, inherent to the extraction and accepted codebase-wide:
 * the rules now resolve before the controller body, so a request that is both
 * malformed and foreign to the business (the controller's 404) answers 422
 * first. The anti-id-probing guard stays a single copy in the controller
 * rather than being mirrored into authorize(); a valid payload from a foreign
 * caller still gets the 404, and a row that does not exist at all still 404s
 * at route binding, before validation runs.
 *
 * The edit body reads presence with `has()`/`filled()` on the request (empty
 * pin is ignored, an explicitly empty store/warehouse list clears the
 * assignments), so those checks deliberately stay in the controller.
 */
final class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Access is the route's `permission:staff edit` middleware and the
        // controller's own authorizeStaff() check, not a validation gate.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', Rule::in(['active', 'suspended', 'invited'])],
            'pin' => ['nullable', 'string', 'size:6', 'regex:/^[0-9]+$/'],
            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => ['integer'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer'],
        ];
    }
}
