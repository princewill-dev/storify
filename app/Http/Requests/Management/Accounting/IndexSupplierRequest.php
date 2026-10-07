<?php

namespace App\Http\Requests\Management\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-22 suppliers list filters.
 *
 * `q` searches name, email and phone (the repository owns the LIKE clause);
 * `per_page` keeps the legacy 20/page default, capped at 100.
 */
final class IndexSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
