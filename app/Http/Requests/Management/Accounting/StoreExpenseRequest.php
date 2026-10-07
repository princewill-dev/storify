<?php

namespace App\Http\Requests\Management\Accounting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-16 — validation for recording an expense.
 *
 * Everything is scoped to the authenticated user's business, so another
 * business's category, ledger account, supplier or payment account id cannot
 * be selected.
 */
final class StoreExpenseRequest extends FormRequest
{
    /**
     * The payment methods this endpoint accepts, canonical for the form picker
     * (ExpenseController::options) as well as the rule below. The expense
     * resources render their labels from here too.
     *
     * @var array<string, string>
     */
    public const PAYMENT_METHODS = [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank Transfer',
        'card' => 'Card',
        'cheque' => 'Cheque',
        'other' => 'Other',
    ];

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
            'expense_date' => ['required', 'date'],
            'expense_category_id' => ['nullable', Rule::exists('expense_categories', 'id')->where('business_id', $businessId)],
            // Legacy validated existence only; deactivated accounts vanished
            // from every picker but a crafted request could still post to one.
            'ledger_account_id' => ['required', Rule::exists('ledger_accounts', 'id')->where('business_id', $businessId)->where('is_active', true)],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('business_id', $businessId)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', Rule::in(array_keys(self::PAYMENT_METHODS))],
            'payment_account_id' => ['nullable', Rule::exists('ledger_accounts', 'id')->where('business_id', $businessId)->where('is_active', true)],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'receipt' => ['nullable', 'file', 'mimes:jpeg,png,jpg,pdf', 'max:5120'],
        ];
    }
}
