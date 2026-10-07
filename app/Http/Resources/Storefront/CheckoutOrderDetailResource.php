<?php

namespace App\Http\Resources\Storefront;

use App\Enums\TransactionStatus;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Http\Request;

/**
 * The order-tracking payload (`GET /storefront/{store}/orders/{orderNumber}`)
 * — the detailed branch of the controller's orderPayload() map moved
 * verbatim: the base row plus customer_email, items and transactions,
 * appended in the order the array literal emitted them so the serialised
 * shape is unchanged.
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
            'customer_email' => $order->customer?->email,
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
