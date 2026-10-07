<?php

namespace App\Http\Resources\Management\Pos;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row on the WS-17 orders list.
 *
 * Mirrors OrderController@payload (list variant) plus the POS linkage. Field
 * names, types and order are the exact contract the controller's old private
 * payload() emitted — consumers and the WS-17 tests assert them, so the
 * `(float)` and `(int)` casts stay on the same fields.
 */
final class OrderSourceResource extends JsonResource
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
            'is_pos' => $order->isPos(),
            'pos_session_id' => $order->pos_session_id,
            'total' => (float) $order->total,
            'amount_paid' => (float) $order->amount_paid,
            'remaining' => (float) $order->remainingBalance(),
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'payment_status' => $order->payment_status?->value,
            'created_at' => $order->created_at?->toISOString(),
        ];
    }
}
