<?php

namespace App\Http\Resources\Management\Accounting;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The WS-22 supplier detail payload: the summary row plus the supplier's
 * bills, payment history and header totals.
 *
 * The bills and payments relations are eager-loaded (with their ordering) by
 * App\Repositories\Accounting\SupplierRepository::loadForDetail() — this
 * resource only shapes what it is given.
 */
final class SupplierDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Supplier $supplier */
        $supplier = $this->resource;

        $bills = $supplier->bills->where('status', '!=', Bill::STATUS_VOID);

        return [
            ...(new SupplierSummaryResource($supplier))->toArray($request),
            // Every bill is listed, void ones included — only the totals
            // below exclude them.
            'bills' => $supplier->bills->map(fn (Bill $bill) => [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'issue_date' => $bill->issue_date?->toDateString(),
                'due_date' => $bill->due_date?->toDateString(),
                'total_kobo' => (int) $bill->total_kobo,
                'balance_kobo' => $bill->remainingBalanceKobo(),
                'status' => $bill->status,
            ])->values()->all(),
            'payments' => $supplier->billPayments->map(fn (BillPayment $payment) => [
                'id' => $payment->id,
                'bill_id' => $payment->bill_id,
                'payment_date' => $payment->payment_date?->toDateString(),
                'amount_kobo' => (int) $payment->amount_kobo,
                'method' => $payment->method,
                // Payment history rows keep the legacy display label (ucfirst
                // with underscores removed → "Bank transfer"). The null check
                // is the pre-refactor methodLabel()'s exact contract; the
                // title-case labels dress the record-payment picker only.
                'method_label' => $payment->method !== null
                    ? ucfirst(str_replace('_', ' ', $payment->method))
                    : null,
                'reference' => $payment->reference,
            ])->values()->all(),
            // The detail header needs these without re-summing the tables
            // client-side; void bills are excluded exactly like Outstanding.
            'totals' => [
                'bills_count' => $supplier->bills->count(),
                'total_billed_kobo' => (int) $bills->sum('total_kobo'),
                'total_paid_kobo' => (int) $bills->sum('amount_paid_kobo'),
                'outstanding_kobo' => max(0, (int) $bills->sum('total_kobo') - (int) $bills->sum('amount_paid_kobo')),
            ],
        ];
    }
}
