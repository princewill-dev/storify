<?php

namespace App\Http\Requests\Management\StockVisibility;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-29 — the min-level editor's single-location save payload, moved verbatim
 * from StockVisibilityController::updateMinLevel().
 *
 * Deliberately the only one of this controller's requests that changes a
 * refusal order: the controller body authorised the location BEFORE it read
 * the payload, and form requests validate during parameter resolution — so a
 * request that is both unauthorised and malformed now answers 422 where it
 * answered 403. That is the known, accepted consequence of the extraction
 * across this codebase: a VALID payload from an unauthorised caller still gets
 * the 403, route-binding 404 still precedes both, and no privilege is
 * escalated. The guard itself stays in the controller body — it must not move
 * into authorize(), which would run before the rules.
 */
final class UpdateMinLevelRequest extends FormRequest
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
            'min_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
