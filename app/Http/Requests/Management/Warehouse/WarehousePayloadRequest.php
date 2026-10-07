<?php

namespace App\Http\Requests\Management\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The warehouse create/edit payload.
 *
 * Create and edit share one contract on purpose: both actions were validated
 * with the same rule set before the extraction (the controller carried them in
 * one private `validated()` method), and the edit form submits the same fields
 * as create. `is_active` stays nullable-and-defaulted — the service keeps the
 * controller's `($data['is_active'] ?? true)` fallback, so an update that omits
 * the flag still reactivates the warehouse exactly as before.
 */
final class WarehousePayloadRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => ['integer', 'exists:users,id'],
        ];
    }
}
