<?php

namespace App\Http\Resources\Pos;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A list row on GET /pos/stores/{store}/invoices — field set, order and value
 * types exactly as the pre-refactor controller emitted them (exact-JSON
 * assertions depend on all three).
 *
 * @property Invoice $resource
 */
final class InvoiceSummaryResource extends JsonResource
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
            'total' => (float) $invoice->total,
            'amount_paid' => (float) $invoice->amount_paid,
            'status' => $invoice->status->value,
            'status_label' => $invoice->status->label(),
            'due_date' => $invoice->due_date->toISOString(),
            'created_at' => $invoice->created_at->toISOString(),
        ];
    }
}
