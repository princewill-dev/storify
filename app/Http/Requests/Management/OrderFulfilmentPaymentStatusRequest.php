<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-12 — the extended payment-status control.
 *
 * Five statuses: the fourth (`failed`) maps onto the cancelled transaction
 * status and the fifth (`unpaid`) voids the transaction instead of deleting
 * it. The sibling `payment-status` route still owns the legacy four-status
 * version, which is why this lives on the legacy `orders/{order}/payment`
 * URI.
 */
final class OrderFulfilmentPaymentStatusRequest extends FormRequest
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
            'payment_status' => ['required', Rule::in(['pending', 'paid', 'refunded', 'failed', 'unpaid'])],
        ];
    }
}
