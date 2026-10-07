<?php

namespace App\Http\Resources\Management;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The order list-row shape — the controller's payload() list variant, moved
 * verbatim. Field names, order and types must not change.
 *
 * The detailed variant (subtotal, items, transactions, delivery address) is
 * OrderDetailResource, which extends this class and appends its keys after
 * these — the same split as TransactionResource / TransactionDetailResource,
 * so both classes keep a single constructor argument and stay safe under
 * JsonResource::collection(), whose mapInto passes the collection key as a
 * second constructor argument.
 *
 * The row renders what OrderRepository::listQuery eager-loads; the one read
 * beyond that is the payment_status accessor, which queries the order's
 * transactions as it always has.
 *
 * WS-17's Pos\OrderSourceController mirrors this variant with the POS linkage
 * added — a deliberate superset, not a divergent copy to converge.
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer' => $order->customer?->full_name,
            'customer_id' => $order->customer_id,
            'store' => $order->store?->name,
            'store_id' => $order->store_id,
            'source' => $order->source,
            'total' => (float) $order->total,
            'amount_paid' => (float) $order->amount_paid,
            'remaining' => (float) $order->remainingBalance(),
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'payment_status' => $order->payment_status?->value,
            'created_at' => $order->created_at?->toISOString(),
        ];
    }
}
