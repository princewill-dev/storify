<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The staff invite payload — StaffController::store()'s inline rules moved
 * verbatim.
 *
 * No rule was added or strengthened: the role name is still a bare `string`
 * (the controller assigns it through Spatie afterwards, exactly as before),
 * and the assignment ids stay plain integers — the controller intersects them
 * with the caller's accessible ids before syncing.
 *
 * Validation already ran before any other statement in store(), so the
 * FormRequest extraction does not reorder anything on this route.
 */
final class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Access is the route's `permission:staff create` middleware, not a
        // validation gate.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email', 'unique:customers,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'role' => ['required', 'string'],
            'pin' => ['nullable', 'string', 'size:6', 'regex:/^[0-9]+$/'],
            'store_ids' => ['nullable', 'array'],
            'store_ids.*' => ['integer'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer'],
        ];
    }
}
