<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-4 (admin console) — ownership-type directory filter validation.
 *
 * `q` is the fix over legacy (a curated list that will outgrow one screen);
 * the page-size cap matches the other admin lists.
 */
class ListOwnershipTypesRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: guard
        // order (403 vs route binding and the 422s) is asserted behaviour.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
