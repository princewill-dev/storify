<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create-invoice validation for the POS (legacy `$request->validate()` in
 * `Pos\InvoiceController::store()`), rules, order and wording unchanged.
 *
 * Two deliberate carry-overs:
 *
 * 1. The store reachability guard stays on the route middleware
 *    (EnsurePosStoreAccess), not here — it must answer 403 before validation
 *    can answer 422.
 * 2. `customer_id` and `service_charge_id` keep the legacy bare `exists:`
 *    rules. The service re-scopes the service charge to the store when it
 *    reads the amount, so a foreign id contributes zero rather than leaking;
 *    tightening the rule would change which payloads are rejected, which is
 *    not this refactor's job.
 *
 * `subtotal` and `total` are required but the service ignores their values
 * and recomputes both.
 */
final class StoreInvoiceRequest extends FormRequest
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
            'recipient_name' => ['required', 'string', 'max:255'],
            'recipient_email' => ['nullable', 'email', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:50'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'discount_value' => ['nullable', 'numeric', 'min:0'],
            'total' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'send_now' => ['nullable', 'boolean'],
            'service_charge_id' => ['nullable', 'exists:service_charges,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
