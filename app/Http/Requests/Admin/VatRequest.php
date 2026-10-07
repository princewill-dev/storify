<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-12 — the VAT-rate create/edit payload.
 *
 * Create and edit share one contract on purpose: the previous controller
 * validated both actions with the same rule set. A rate is a percentage
 * between 0 and 100 (0 is how "Disable VAT" is expressed, so it must stay
 * valid), `effective_at` defaults to now in VatService, and the write
 * ignores the submitted `active` flag on create — the new record always
 * becomes the active one.
 */
final class VatRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard deliberately stays in the controller so its
        // order relative to route binding is unchanged.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'effective_at' => ['nullable', 'date'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
