<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-5 (admin console) — `PATCH /api/v1/admin/orders/{order}/payment-status`.
 *
 * Legacy validated `required|string` and quietly defaulted unknown values to
 * pending; only the four transitions its UI exposed are accepted. `pending`
 * and `partial` remain badge states, not accepted writes.
 */
final class UpdateOrderPaymentStatusRequest extends FormRequest
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
            'payment_status' => ['required', Rule::in(['unpaid', 'paid', 'refunded', 'failed'])],
        ];
    }
}
