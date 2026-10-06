<?php

namespace App\Http\Resources\Management\Invoice;

use App\Enums\TransactionStatus;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-21 — the document read model behind show and every action response:
 * summary fields plus address, totals, the discount amount the legacy views
 * mis-rendered, the public payment URL, line items and the confirmed payment
 * history.
 *
 * The remaining balance and discount amount arrive pre-computed from
 * App\Services\Management\InvoiceService so both live in exactly one place.
 *
 * @property Invoice $resource
 */
final class InvoiceDetailResource extends JsonResource
{
    public function __construct(
        Invoice $invoice,
        private readonly int $remainingKobo,
        private readonly int $discountKobo,
    ) {
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
            ...(new InvoiceSummaryResource($invoice, $this->remainingKobo))->toArray($request),
            'currency' => $invoice->business?->currency ?: 'NGN',
            'recipient_address' => $invoice->recipient_address,
            'subtotal' => (float) $invoice->subtotal,
            'tax_rate' => (float) $invoice->tax_rate,
            'tax_amount' => (float) $invoice->tax_amount,
            'discount_type' => $invoice->discount_type,
            'discount_value' => (float) $invoice->discount_value,
            // The legacy views rendered `discount_value` as an amount even when
            // it was a percentage; the computed amount is what the UI shows now.
            'discount_amount' => Naira::floatFromKobo($this->discountKobo),
            'notes' => $invoice->notes,
            'terms' => $invoice->terms,
            'sent_at' => $invoice->sent_at?->toISOString(),
            'paid_at' => $invoice->paid_at?->toISOString(),
            'voided_at' => $invoice->voided_at?->toISOString(),
            'payment_url' => $invoice->payment_token
                ? route('invoice.pay.show', ['token' => $invoice->payment_token])
                : null,
            'items' => $invoice->items->map(fn ($item) => [
                'id' => $item->id,
                'description' => $item->description,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'amount' => (float) $item->amount,
            ])->all(),
            'transactions' => $invoice->transactions
                ->where('status', '!=', TransactionStatus::PENDING)
                ->sortByDesc('id')
                ->values()
                ->map(fn (Transaction $transaction) => [
                    'id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'amount' => (float) $transaction->amount,
                    'status' => $transaction->status->value,
                    'status_label' => $transaction->status->label(),
                    'method' => $transaction->metadata['source'] ?? null,
                    'note' => $transaction->metadata['note'] ?? null,
                    'paid_at' => $transaction->paid_at?->toISOString(),
                    'created_at' => $transaction->created_at?->toISOString(),
                ])->all(),
        ];
    }
}
