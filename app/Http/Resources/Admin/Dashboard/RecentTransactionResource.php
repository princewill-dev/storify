<?php

namespace App\Http\Resources\Admin\Dashboard;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS7 — one row of the recent-transactions feed (ten latest confirmed,
 * platform-wide like legacy's `$stats['recent_transactions']`).
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
            'reference' => $transaction->reference,
            'order_number' => $transaction->order?->order_number,
            'store' => $transaction->order?->store?->name,
            'payment_method' => $transaction->paymentMethod?->name,
            'amount' => (float) $transaction->amount,
            'status' => $transaction->status instanceof TransactionStatus
                ? $transaction->status->value
                : (string) $transaction->status,
            'created_at' => $transaction->created_at?->toISOString(),
        ];
    }
}
