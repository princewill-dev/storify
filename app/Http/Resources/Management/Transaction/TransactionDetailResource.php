<?php

namespace App\Http\Resources\Management\Transaction;

use App\Models\Transaction;
use Illuminate\Http\Request;

/**
 * The base TransactionController's show payload — the list row plus the
 * detail fields the inline payload() appended, in the same order.
 *
 * `store_balance_before`/`store_balance_after` are echoed exactly as read
 * (the base payload applied no casts); `bank` is null when no store bank is
 * attached, and `is_verified` is deliberately absent — the WS-18 detail
 * resource is a different contract.
 */
class TransactionDetailResource extends TransactionResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Transaction $transaction */
        $transaction = $this->resource;

        return [
            ...parent::toArray($request),
            'gateway_reference' => $transaction->gateway_reference,
            'store_balance_before' => $transaction->store_balance_before,
            'store_balance_after' => $transaction->store_balance_after,
            'bank' => $transaction->storeBank ? [
                'bank_name' => $transaction->storeBank->bank_name,
                'account_number' => $transaction->storeBank->account_number,
                'account_name' => $transaction->storeBank->account_name,
            ] : null,
            'metadata' => $transaction->metadata,
        ];
    }
}
