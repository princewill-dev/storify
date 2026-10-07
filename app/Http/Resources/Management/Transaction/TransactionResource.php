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
 * fee/net use Naira::floatFromKobo, the same kobo-to-naira expression the
 * inline payload divided by 100 — the numbers reach the wire unchanged.
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

        return [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
            'amount' => (float) $transaction->amount,
            'fee' => $transaction->fee_kobo !== null ? Naira::floatFromKobo($transaction->fee_kobo) : null,
            'net' => $transaction->net_kobo !== null ? Naira::floatFromKobo($transaction->net_kobo) : null,
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
