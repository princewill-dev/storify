<?php

namespace App\Http\Resources\Storefront;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `order` object of the account order-detail payload — the controller's
 * inline map moved verbatim: field names, order and types unchanged.
 *
 * Deliberately not an extension of AccountOrderResource: `remaining` sits
 * between amount_paid and status in the serialised order, and appending the
 * extra keys after the parent's would move it — the storefront SPA and the
 * exact-JSON tests read the object verbatim.
 *
 * The caller passes the order eager-loaded by
 * AccountRepository::findOrderForCustomer; this class issues no queries.
 */
final class AccountOrderDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return [
            'order_number' => $order->order_number,
            'store' => $order->store?->name,
            'total' => (float) $order->total,
            'amount_paid' => (float) $order->amount_paid,
            'remaining' => (float) $order->remainingBalance(),
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'created_at' => $order->created_at?->toISOString(),
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
                'is_digital' => (bool) $item->is_digital,
            ])->values()->all(),
            'transactions' => $order->transactions->map(fn ($transaction) => [
                'reference' => $transaction->reference,
                'amount' => (float) $transaction->amount,
                'status' => $transaction->status instanceof TransactionStatus ? $transaction->status->value : $transaction->status,
            ])->values()->all(),
        ];
    }
}
