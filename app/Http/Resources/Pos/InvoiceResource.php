<?php

namespace App\Http\Resources\Pos;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The payload the create and record-payment actions return — the legacy
 * private `formatInvoice()`, field set, order and value types unchanged.
 *
 * On the create path `items` is already loaded and `transactions` lazy-loads
 * (empty); after a payment the repository refreshes and loads both first.
 * That difference is inherited, not a contract of this class.
 *
 * @property Invoice $resource
 */
final class InvoiceResource extends JsonResource
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
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->label(),
            'total' => (float) $invoice->total,
            'amount_paid' => (float) $invoice->amount_paid,
            'remaining' => $invoice->remainingBalance(),
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
