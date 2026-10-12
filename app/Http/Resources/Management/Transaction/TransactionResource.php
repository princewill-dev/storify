<?php

namespace App\Http\Resources\Management\Transaction;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A base TransactionController list row — the controller's inline payload()
 * verbatim: field names, order and types included.
 *
 * Deliberately separate from App\Http\Resources\Management\TransactionResource
 * (the WS-18 slice behind the shared routes, served by TransactionParityController):
 * that payload carries status_label, store, customer, payment-method code and
 * payment-slip fields, while this slice returns exactly the fields the base
 * payload emitted. The two are not interchangeable.
 *
 * The money fields use Naira::floatFromKobo, the same kobo-to-naira expression
 * the inline payload divided by 100 — the numbers reach the wire unchanged.
 *
 * `fee` became `processing_fee` here too, and `net` is now derived rather than
 * read from the column, so this slice cannot offer a second, older answer to
 * "what did the customer pay and what reached the bank" than the WS-18 slice
 * does. Nothing routes to it today — `ws18-transactions.php` re-registers every
 * URI the thin controller declared — which is precisely why it is worth keeping
 * in step: a payload left carrying the previous meaning is a trap for whoever
 * reconnects it.
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Transaction $transaction */
        $transaction = $this->resource;
        $processingFee = $transaction->processingFeeKobo();
        $net = $transaction->netKobo();

        return [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
            'amount' => (float) $transaction->amount,
            'processing_fee' => $processingFee !== null ? Naira::floatFromKobo($processingFee) : null,
            'net' => $net !== null ? Naira::floatFromKobo($net) : null,
            'currency' => $transaction->currency,
            'status' => $transaction->status instanceof TransactionStatus ? $transaction->status->value : $transaction->status,
            'order' => $transaction->order?->order_number,
            'invoice' => $transaction->invoice?->invoice_number,
            'payment_method' => $transaction->paymentMethod?->name,
            'paid_at' => $transaction->paid_at?->toISOString(),
            'created_at' => $transaction->created_at?->toISOString(),
        ];
    }
}
