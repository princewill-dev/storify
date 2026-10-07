<?php

namespace App\Http\Requests\Management;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The four-status payment setter on PUT orders/{order}/payment-status — the
 * controller's inline rules, moved verbatim.
 *
 * Deliberately NOT merged with OrderFulfilmentPaymentStatusRequest, which
 * extends the same control with a fifth status (`unpaid`) on the legacy
 * orders/{order}/payment URI: that version voids a transaction instead of
 * mapping it, aligns paid_at with the mapped status and stamps manual rows
 * with the cash method and NGN. This four-status contract does none of that,
 * so the two rule sets stay apart.
 *
 * The tenant guard stays in the controller body, ahead of the write; a valid
 * payload from an unauthorised caller still gets 403. (Extraction means an
 * unauthorised AND malformed request now fails validation first — accepted
 * codebase-wide.)
 */
final class UpdateOrderPaymentStatusRequest extends FormRequest
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
            'payment_status' => ['required', Rule::in(['pending', 'paid', 'refunded', 'failed'])],
        ];
    }
}
