<?php

namespace App\Http\Requests\Management\Accounting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-22 — validation for creating a bill with its line items.
 *
 * Everything is scoped to the authenticated user's business, so another
 * business's supplier, expense account or product id cannot be selected.
 */
final class StoreBillRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $businessId = $this->user()->business_id;

        return [
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('business_id', $businessId)],
            // Improvement on legacy: a duplicate bill number used to reach the
            // unique index and surface as a 500; it is a field error now.
            'bill_number' => ['nullable', 'string', 'max:40',
                Rule::unique('bills', 'bill_number')->where('business_id', $businessId)],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            // Deactivated accounts vanish from every picker but a crafted
            // request could still post to one (legacy only checked existence).
            'items.*.expense_account_id' => ['nullable', Rule::exists('ledger_accounts', 'id')
                ->where('business_id', $businessId)
                ->where('is_active', true)],
            'items.*.product_id' => ['nullable', Rule::exists('products', 'id')->where('business_id', $businessId)],
        ];
    }
}
