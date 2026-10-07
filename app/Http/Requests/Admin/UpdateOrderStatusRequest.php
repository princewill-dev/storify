<?php

namespace App\Http\Requests\Admin;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-5 (admin console) — `PATCH /api/v1/admin/orders/{order}/status`.
 *
 * The status card's payload: the target status (enum-bounded, as the
 * controller validated it) and an optional note that is appended to the
 * order's notes with a dated stamp.
 */
final class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard deliberately stays in the controller, not
        // here: it is the audience decision the order-oversight tests assert.
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
