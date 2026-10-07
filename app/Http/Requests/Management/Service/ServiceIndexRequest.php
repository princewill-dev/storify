<?php

namespace App\Http\Requests\Management\Service;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The WS-30 ServiceController's list filters — the rules that controller
 * carried inline, verbatim.
 *
 * Unlike the base Product/Category slices (which never validated their list
 * filters), this index always did, so the extraction is mechanical: the same
 * inputs still pass and the same out-of-range per_page is still a 422.
 *
 * The index has no body-side guard, so nothing here reorders a refusal — the
 * route's permission middleware still answers an unauthorised caller before
 * validation runs.
 */
class ServiceIndexRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'store_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
