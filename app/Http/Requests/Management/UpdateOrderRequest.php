<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-13 — the fields the order edit form owns.
 *
 * Legacy's edit form rendered Status and Payment Status selects, but its
 * update() only validated shipping/tax/notes — the two controls were
 * silently discarded. They are deliberately absent here instead of cloned;
 * only the three fields the form actually owns are accepted.
 */
class UpdateOrderRequest extends FormRequest
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
            'shipping_fee' => ['required', 'numeric', 'min:0'],
            'tax' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
