<?php

namespace App\Http\Requests\Management\Service;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The WS-30 ServiceController's update payload — the rules that controller
 * carried inline, verbatim.
 *
 * `status` and `store_id` are validated here rather than left to the legacy
 * controller's "persist anything" behaviour (verify pass corrections #5/#6).
 * `store_id` stays a plain integer rule: the controller re-checks it against
 * the caller's accessible, non-deleted stores and answers its own 422
 * "Invalid store selection." (deliberate anti-id-probing — a foreign or
 * deleted store id must not read as "exists but forbidden").
 *
 * Extracting the rules moves validation ahead of the controller body: the
 * per-service 403 now runs after a well-formed payload is confirmed, so a
 * request that is both unauthorised and malformed answers 422 where it used
 * to answer 403. That is inherent to the extraction across this codebase; a
 * valid payload from an unauthorised caller still gets 403, and route-binding
 * 404 precedes both. The guard itself must not move into authorize(), which
 * would reorder it against validation.
 */
class UpdateServiceRequest extends FormRequest
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
            'store_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            // Legacy persisted any store id and any status string here; both
            // are validated now rather than cloned (verify pass corrections
            // #5/#6).
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:jpeg,jpg,png,gif,webp', 'max:2048'],
            'primary_image_id' => ['nullable', 'integer'],
            'primary_image_index' => ['nullable', 'integer', 'min:0'],
            'delete_image_ids' => ['nullable', 'array'],
            'delete_image_ids.*' => ['integer'],
        ];
    }
}
