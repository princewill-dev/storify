<?php

namespace App\Http\Resources\Management;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-19 — a transaction row on the customer detail screen, with the payment
 * method and order number the legacy table rendered.
 */
final class CustomerParityTransactionResource extends JsonResource
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
            'order_number' => $transaction->order?->order_number,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'status' => $transaction->status?->value,
            'status_label' => $transaction->status_label,
            'payment_method' => $transaction->paymentMethod?->name,
            'paid_at' => $transaction->paid_at?->toISOString(),
            'created_at' => $transaction->created_at?->toISOString(),
        ];
    }
}
