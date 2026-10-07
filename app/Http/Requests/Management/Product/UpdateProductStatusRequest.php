<?php

namespace App\Http\Requests\Management\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The base ProductController's activate/deactivate payload — the single rule
 * the controller carried inline, verbatim.
 *
 * Deliberately separate from App\Http\Requests\Management\ProductStatusRequest
 * (the WS-14 slice behind the shared routes, served by ProductFormController):
 * the rule is identical today, but the two slices' contracts are kept apart
 * like the rest of the product layers, so a future change to either surface
 * cannot silently alter the other.
 */
class UpdateProductStatusRequest extends FormRequest
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
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
