<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The WS-16 expense detail payload: the summary row plus the payment account,
 * receipt rendering, journal trace and the reversal link.
 *
 * The reversal entry is looked up by ExpenseRepository::reversalEntryFor() and
 * handed in — this resource only shapes what it is given.
 */
final class ExpenseDetailResource extends JsonResource
{
    private ?JournalEntry $reversal;

    public function __construct(Expense $expense, ?JournalEntry $reversal)
    {
        parent::__construct($expense);

        $this->reversal = $reversal;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Expense $expense */
        $expense = $this->resource;

        $entry = $expense->journalEntry;

        return [
            ...(new ExpenseSummaryResource($expense))->toArray($request),
            'payment_account' => $expense->paymentAccount ? [
                'id' => $expense->paymentAccount->id,
                'code' => $expense->paymentAccount->code,
                'name' => $expense->paymentAccount->name,
            ] : null,
            'receipt' => $expense->receipt_path ? [
                'url' => asset('storage/'.$expense->receipt_path),
                'is_pdf' => str_ends_with(strtolower($expense->receipt_path), '.pdf'),
            ] : null,
            'created_by' => $expense->created_by ? (int) $expense->created_by : null,
            'created_at' => $expense->created_at?->toISOString(),
            'journal_entry' => $entry ? [
                'id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'entry_date' => $entry->entry_date?->toDateString(),
                'status' => $entry->status,
                'memo' => $entry->memo,
                'lines' => $entry->lines->map(fn (JournalLine $line) => [
                    'id' => $line->id,
                    'account_code' => $line->account?->code,
                    'account_name' => $line->account?->name,
                    'description' => $line->description,
                    'debit_kobo' => (int) $line->debit_kobo,
                    'credit_kobo' => (int) $line->credit_kobo,
                ])->all(),
            ] : null,
            // Legacy could show the banner on a reversal entry but had no link
            // the other way; surfacing it here answers "why is this expense's
            // entry void?" from the expense itself.
            'reversal_entry' => $this->reversal ? [
                'id' => $this->reversal->id,
                'entry_number' => $this->reversal->entry_number,
                'entry_date' => $this->reversal->entry_date?->toDateString(),
                'status' => $this->reversal->status,
            ] : null,
        ];
    }
}
