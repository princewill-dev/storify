<?php

namespace App\Http\Resources\Storefront;

use App\Enums\TransactionStatus;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Http\Request;

/**
 * The order-tracking payload (`GET /storefront/{store}/orders/{orderNumber}`)
 * — the base row plus the order's items and its payments.
 *
 * `customer_email` was carried here and is deliberately gone. This endpoint is
 * public — the track page reads it without a session — so that field published
 * the buyer's address to anyone holding the order number, and nothing ever read
 * it: the storefront had no reference to the key at all. The customer's own
 * views get the address from their account, which is authenticated.
 *
 * The caller passes the order eager-loaded by
 * CheckoutRepository::findOrderByNumber(..., ['items', 'transactions']); this
 * class issues no queries of its own.
 */
final class CheckoutOrderDetailResource extends CheckoutOrderResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return [
            ...parent::toArray($request),
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
                'is_digital' => (bool) $item->is_digital,
            ])->values()->all(),
            'transactions' => $order->transactions->map(fn (Transaction $transaction) => [
                'reference' => $transaction->reference,
                'amount' => (float) $transaction->amount,
                'status' => $transaction->status instanceof TransactionStatus ? $transaction->status->value : $transaction->status,
                'paid_at' => $transaction->paid_at?->toISOString(),
            ])->values()->all(),
        ];
    }
}
