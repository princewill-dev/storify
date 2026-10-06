<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The WS-22 bill detail payload: the summary row plus items, payments, the
 * journal trace and the record-payment pickers.
 *
 * The reversal entry and the payment accounts are looked up by
 * BillRepository::reversalEntryFor()/paymentAccountOptions() and handed in —
 * this resource only shapes what it is given.
 */
final class BillDetailResource extends JsonResource
{
    private ?JournalEntry $reversal;

    /** @var array<int, array{id: int, code: string, name: string}> */
    private array $paymentAccounts;

    /**
     * @param  array<int, array{id: int, code: string, name: string}>  $paymentAccounts
     */
    public function __construct(Bill $bill, ?JournalEntry $reversal, array $paymentAccounts)
    {
        parent::__construct($bill);

        $this->reversal = $reversal;
        $this->paymentAccounts = $paymentAccounts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Bill $bill */
        $bill = $this->resource;

        $entry = $bill->journalEntry;

        return [
            ...(new BillSummaryResource($bill))->toArray($request),
            'notes' => $bill->notes,
            'supplier_detail' => $bill->supplier ? [
                'id' => $bill->supplier->id,
                'name' => $bill->supplier->name,
                'email' => $bill->supplier->email,
                'phone' => $bill->supplier->phone,
                'address' => $bill->supplier->address,
            ] : null,
            'items' => $bill->items->map(fn (BillItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_cost_kobo' => (int) $item->unit_cost_kobo,
                'amount_kobo' => (int) $item->amount_kobo,
                'expense_account' => $item->expenseAccount ? [
                    'id' => $item->expenseAccount->id,
                    'code' => $item->expenseAccount->code,
                    'name' => $item->expenseAccount->name,
                ] : null,
                'product' => $item->product ? [
                    'id' => $item->product->id,
                    'name' => $item->product->name,
                    'product_code' => $item->product->product_code,
                ] : null,
            ])->values()->all(),
            'payments' => $bill->payments->map(fn (BillPayment $payment) => [
                'id' => $payment->id,
                'payment_date' => $payment->payment_date?->toDateString(),
                'amount_kobo' => (int) $payment->amount_kobo,
                'method' => $payment->method,
                // Payment history rows keep the legacy display label
                // (ucfirst with underscores removed → "Bank transfer"), which
                // is also what SupplierController::methodLabel() returns. The
                // title-case picker labels dress the form picker only.
                'method_label' => $payment->method
                    ? ucfirst(str_replace('_', ' ', $payment->method))
                    : null,
                'reference' => $payment->reference,
                'payment_account' => $payment->paymentAccount ? [
                    'id' => $payment->paymentAccount->id,
                    'code' => $payment->paymentAccount->code,
                    'name' => $payment->paymentAccount->name,
                ] : null,
                'journal_entry_id' => $payment->journal_entry_id ? (int) $payment->journal_entry_id : null,
            ])->values()->all(),
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
            'reversal_entry' => $this->reversal ? [
                'id' => $this->reversal->id,
                'entry_number' => $this->reversal->entry_number,
                'entry_date' => $this->reversal->entry_date?->toDateString(),
                'status' => $this->reversal->status,
            ] : null,
            // The legacy detail screen passed the payment pickers to its
            // Record Payment modal; the SPA gets them here in the same trip.
            'payment_accounts' => $this->paymentAccounts,
            'created_by' => $bill->created_by ? (int) $bill->created_by : null,
            'created_at' => $bill->created_at?->toISOString(),
        ];
    }
}
