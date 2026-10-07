<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-20 — the staff directory filters.
 *
 * The controller's inline rules moved verbatim. `store_id` is the store
 * *code* the SPA holds; the lookup and its 404 stay in the controller,
 * because a code the business does not own is a 404 the controller owns,
 * not a validation gate.
 */
final class StaffParityIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Access is the route's `permission:staff view` middleware; the
        // directory scope itself is applied by StaffParityRepository.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'invited'])],
            'store_id' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
