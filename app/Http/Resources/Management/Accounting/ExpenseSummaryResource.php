<?php

namespace App\Http\Resources\Management\Accounting;

use App\Http\Requests\Management\Accounting\StoreExpenseRequest;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the WS-16 expenses list (`GET accounting/expenses`).
 *
 * This is the flat row shape the shared AccountingController endpoint already
 * returned; `data` keeps it so existing consumers keep working.
 */
final class ExpenseSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Expense $expense */
        $expense = $this->resource;

        return [
            'id' => $expense->id,
            'expense_date' => $expense->expense_date?->toDateString(),
            'description' => $expense->description,
            'reference' => $expense->reference,
            'category' => $expense->category?->name,
            // Legacy fell back to the ledger account when no category was set.
            'category_label' => $expense->category?->name ?? $expense->ledgerAccount?->name,
            'supplier' => $expense->supplier?->name,
            'ledger_account' => $expense->ledgerAccount ? [
                'id' => $expense->ledgerAccount->id,
                'code' => $expense->ledgerAccount->code,
                'name' => $expense->ledgerAccount->name,
            ] : null,
            'amount_kobo' => (int) $expense->amount_kobo,
            'tax_kobo' => (int) $expense->tax_kobo,
            'total_kobo' => (int) $expense->total_kobo,
            'payment_method' => $expense->payment_method,
            // Unknown values keep the legacy derived label; the map dresses the
            // known picker values ("Bank Transfer", not "Bank transfer").
            'payment_method_label' => $expense->payment_method
                ? (StoreExpenseRequest::PAYMENT_METHODS[$expense->payment_method] ?? ucfirst(str_replace('_', ' ', $expense->payment_method)))
                : null,
            'status' => $expense->status,
            'journal_entry_id' => $expense->journal_entry_id ? (int) $expense->journal_entry_id : null,
            'has_receipt' => (bool) $expense->receipt_path,
            'receipt_url' => $expense->receipt_path
                ? asset('storage/'.$expense->receipt_path)
                : null,
        ];
    }
}
