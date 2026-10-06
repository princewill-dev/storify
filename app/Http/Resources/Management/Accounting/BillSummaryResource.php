<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\Bill;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the WS-22 bills list (`GET accounting/bills`).
 *
 * This is the flat row shape the shared AccountingController endpoint already
 * returned; `data` keeps it so existing consumers keep working.
 */
final class BillSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Bill $bill */
        $bill = $this->resource;

        $balanceKobo = $bill->remainingBalanceKobo();

        return [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'supplier_id' => $bill->supplier_id ? (int) $bill->supplier_id : null,
            'supplier' => $bill->supplier?->name,
            'issue_date' => $bill->issue_date?->toDateString(),
            'due_date' => $bill->due_date?->toDateString(),
            'subtotal_kobo' => (int) $bill->subtotal_kobo,
            'tax_kobo' => (int) $bill->tax_kobo,
            'total_kobo' => (int) $bill->total_kobo,
            'paid_kobo' => (int) $bill->amount_paid_kobo,
            'balance_kobo' => $balanceKobo,
            // The legacy Blade computed this inline to paint the due date red;
            // doing it server-side keeps the SPA from re-deriving the rule.
            'is_overdue' => $bill->due_date !== null
                && $balanceKobo > 0
                && ! in_array($bill->status, [Bill::STATUS_VOID, Bill::STATUS_PAID], true)
                && $bill->due_date->lt(today()),
            'status' => $bill->status,
            'items_count' => $bill->items_count !== null
                ? (int) $bill->items_count
                : ($bill->relationLoaded('items') ? $bill->items->count() : null),
        ];
    }
}
