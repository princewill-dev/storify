<?php

namespace App\Http\Resources\Admin;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-5 — the transaction row returned after an admin status override.
 *
 * Field names, types and order are exactly what this endpoint emitted before
 * the extraction (the override test asserts exact JSON paths): `amount` stays
 * a float, `status`/`status_label` read off the enum the model casts, and the
 * timestamps are ISO-8601 strings or null.
 *
 * @property-read Transaction $resource
 */
final class TransactionStatusOverrideResource extends JsonResource
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
            'currency' => $transaction->currency,
            'status' => $transaction->status?->value,
            'status_label' => $transaction->status?->label(),
            'order' => $transaction->order?->order_number,
            'paid_at' => $transaction->paid_at?->toISOString(),
            'created_at' => $transaction->created_at?->toISOString(),
        ];
    }
}
