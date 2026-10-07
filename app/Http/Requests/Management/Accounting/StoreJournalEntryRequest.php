<?php

namespace App\Http\Requests\Management\Accounting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-23 — validation for creating a manual journal entry.
 *
 * Money on the wire is integer kobo (`lines.*.debit_kobo` / `credit_kobo`)
 * per the house rules; the SPA converts the naira floats it collects once.
 *
 * The account ownership check deliberately has no `exists` rule here: an
 * unknown or foreign account id must stay a 422 "invalid selection" rather
 * than become a 403 (anti-id-probing), and it has to run against the
 * normalised line set because blank rows are dropped first — that lives in
 * JournalService::normaliseLines().
 */
final class StoreJournalEntryRequest extends FormRequest
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
        return [
            'entry_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:1000'],
            'reference' => ['nullable', 'string', 'max:100'],
            'save_as_draft' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ledger_account_id' => ['required', 'integer'],
            'lines.*.debit_kobo' => ['nullable', 'integer', 'min:0'],
            'lines.*.credit_kobo' => ['nullable', 'integer', 'min:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
