<?php

namespace App\Http\Resources\Pos;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The POS invoice document behind GET /pos/stores/{store}/invoices/{id} —
 * field set, order and value types exactly as the pre-refactor controller
 * emitted them.
 *
 * `remaining` and both line-item/payment maps are the legacy per-row maths
 * and shaping, including the `!= 'pending'` payments filter, kept verbatim.
 *
 * @property Invoice $resource
 */
final class InvoiceDetailResource extends JsonResource
{
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
            'recipient_name' => $invoice->recipient_name ?? $invoice->customer?->full_name,
            'recipient_email' => $invoice->recipient_email,
            'recipient_phone' => $invoice->recipient_phone,
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->label(),
            'issue_date' => $invoice->issue_date->toISOString(),
            'due_date' => $invoice->due_date->toISOString(),
            'subtotal' => (float) $invoice->subtotal,
            'tax_rate' => (float) $invoice->tax_rate,
            'tax_amount' => (float) $invoice->tax_amount,
            'discount_value' => (float) $invoice->discount_value,
            'total' => (float) $invoice->total,
            'amount_paid' => (float) $invoice->amount_paid,
            'remaining' => $invoice->remainingBalance(),
            'notes' => $invoice->notes,
            'created_at' => $invoice->created_at->toISOString(),
            'items' => $invoice->items->map(fn ($i) => [
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'amount' => (float) $i->amount,
            ]),
            'transactions' => $invoice->transactions->where('status', '!=', 'pending')->map(fn ($tx) => [
                'reference' => $tx->reference,
                'amount' => (float) $tx->amount,
                'status' => $tx->status->value,
                'status_label' => $tx->status->label(),
                'created_at' => $tx->created_at->toISOString(),
            ]),
        ];
    }
}
