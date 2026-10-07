<?php

namespace App\Http\Requests\Admin;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-5 (admin console) — `PUT /api/v1/admin/orders/{order}`.
 *
 * The edit form's real surface: shipping fee, tax, status and notes. The
 * customer/delivery inputs legacy rendered were never validated or fillable,
 * and its payment_status select was a model-level no-op — none of them are
 * accepted here.
 *
 * The rules are byte-for-byte the ones the controller body ran (including
 * `sometimes` so a partial edit never blanks a field it did not mention).
 */
final class UpdateOrderRequest extends FormRequest
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
            'shipping_fee' => ['sometimes', 'numeric', 'min:0'],
            'tax' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
