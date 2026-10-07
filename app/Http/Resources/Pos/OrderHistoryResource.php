<?php

namespace App\Http\Resources\Pos;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row on the POS order-history list.
 *
 * Field names, types and order are the exact contract the controller's old
 * inline map emitted — the POS screens assert them — so the `(float)` cast
 * and the relation reads stay on the same fields. The relation reads are
 * collection reads on the eager-loaded items/transactions, never queries.
 *
 * `has_refund` and `refund_status` read the literal status values rather than
 * an enum comparison, exactly as the inline map did.
 *
 * @property-read Order $resource
 */
final class OrderHistoryResource extends JsonResource
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
            'total' => (float) $order->total,
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'created_at' => $order->created_at->toISOString(),
            'items_count' => $order->items->count(),
            'items' => $order->items->take(3)->map(fn ($item) => $item->product_name),
            'more_items' => max(0, $order->items->count() - 3),
            'has_refund' => $order->transactions->contains(fn ($transaction) => in_array($transaction->status?->value, ['refunded', 'refund_pending'], true)),
            'refund_status' => $order->transactions->first(fn ($transaction) => in_array($transaction->status?->value, ['refunded', 'refund_pending'], true))?->status?->value,
        ];
    }
}
