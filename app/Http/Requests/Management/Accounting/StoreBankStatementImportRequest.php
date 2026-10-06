<?php

namespace App\Http\Requests\Management\Accounting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-37 — statement import payload.
 *
 * The ledger account and store bank must belong to the caller's business:
 * the `exists` rules are scoped to it, so another business's ids fail
 * validation before any lookup runs.
 */
final class StoreBankStatementImportRequest extends FormRequest
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
        $businessId = (int) $this->user()->business_id;

        return [
            'ledger_account_id' => ['required', 'integer', Rule::exists('ledger_accounts', 'id')->where('business_id', $businessId)],
            'store_bank_id' => ['nullable', 'integer', Rule::exists('store_banks', 'id')->where('business_id', $businessId)],
            'statement_date' => ['nullable', 'date'],
            'opening_balance_kobo' => ['nullable', 'integer', 'min:0'],
            // The snapshot columns are unsigned kobo, so an overdrawn closing
            // balance is refused up front rather than hitting the database.
            'closing_balance_kobo' => ['nullable', 'integer', 'min:0'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ];
    }
}
