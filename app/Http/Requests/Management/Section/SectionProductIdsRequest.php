<?php

namespace App\Http\Requests\Management\Section;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-36 — the assign/unassign payload.
 *
 * Assign and unassign share one contract on purpose: both were validated with
 * the same rule set before the extraction. The controller still owns the
 * business handling of the ids — the assignment's "could not be found"
 * refusal and the per-product checks — because those are 422 responses, not
 * request-shape rules.
 */
final class SectionProductIdsRequest extends FormRequest
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
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
        ];
    }
}
