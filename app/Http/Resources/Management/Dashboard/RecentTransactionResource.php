<?php

namespace App\Http\Resources\Management\Dashboard;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-28 — one row of the recent-transactions panel (the five newest
 * transactions in scope).
 *
 * `customer` keeps the legacy panel's Walk-in fallback for a payment with no
 * order customer.
 *
 * @property-read Transaction $resource
 */
final class RecentTransactionResource extends JsonResource
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
            'customer' => $transaction->order?->customer?->full_name ?? 'Walk-in',
            'amount' => (float) $transaction->amount,
            'status' => $transaction->status instanceof TransactionStatus ? $transaction->status->value : $transaction->status,
            'created_at' => $transaction->created_at?->toISOString(),
        ];
    }
}
