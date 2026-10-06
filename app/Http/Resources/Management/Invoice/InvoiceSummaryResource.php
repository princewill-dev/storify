<?php

namespace App\Http\Resources\Management\Invoice;

use App\Models\Invoice;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-21 — list row for an invoice.
 *
 * The remaining balance is per-row kobo maths owned by
 * App\Services\Management\InvoiceService; the controller computes it and
 * passes it in, and it is converted once here to the JSON float the legacy
 * endpoints emitted.
 *
 * @property Invoice $resource
 */
final class InvoiceSummaryResource extends JsonResource
{
    public function __construct(Invoice $invoice, private readonly int $remainingKobo)
    {
        parent::__construct($invoice);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Invoice $invoice */
        $invoice = $this->resource;

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->label(),
            'recipient_name' => $invoice->recipient_name ?? $invoice->customer?->full_name,
            'recipient_email' => $invoice->recipient_email,
            'recipient_phone' => $invoice->recipient_phone,
            'customer' => $invoice->customer ? [
                'id' => $invoice->customer->id,
                'account_id' => $invoice->customer->account_id,
                'name' => $invoice->customer->full_name,
                'email' => $invoice->customer->email,
                'phone' => $invoice->customer->phone,
            ] : null,
            'store' => $invoice->store ? [
                'id' => $invoice->store->id,
                'name' => $invoice->store->name,
                'store_id' => $invoice->store->store_id,
            ] : null,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'total' => (float) $invoice->total,
            'amount_paid' => (float) $invoice->amount_paid,
            'remaining' => Naira::floatFromKobo($this->remainingKobo),
            'items_count' => (int) ($invoice->items_count ?? ($invoice->relationLoaded('items') ? $invoice->items->count() : $invoice->items()->count())),
            'created_at' => $invoice->created_at?->toISOString(),
        ];
    }
}
