<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-12 — the VAT-rate console list filters.
 *
 * The only filter the list has ever accepted is `per_page`; the ordering is
 * part of the read itself (VatRepository), not something the caller picks.
 */
final class ListVatsRequest extends FormRequest
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
