<?php

namespace App\Http\Requests\Management\Service;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The WS-30 ServiceController's create payload — the rules that controller
 * carried inline, verbatim.
 *
 * `store_id` stays a plain integer rule: the controller re-checks it against
 * the caller's accessible, non-deleted stores and answers its own 422
 * "Invalid store selection." An `exists:` rule would both admit stores the
 * caller cannot reach and turn the deliberate anti-id-probing refusal (a
 * foreign or deleted store id must not read as "exists but forbidden") into a
 * framework rule failure with different copy.
 *
 * Extracting the rules moves validation ahead of the controller body; for a
 * caller who is both blocked by the store guard and malformed this returns
 * the 422 instead of the guard's refusal, which is inherent to the extraction
 * across this codebase. A valid payload from the same caller still reaches
 * the guard, and route-binding 404 still precedes both.
 */
class StoreServiceRequest extends FormRequest
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
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:jpeg,jpg,png,gif,webp', 'max:2048'],
            'primary_image_index' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
