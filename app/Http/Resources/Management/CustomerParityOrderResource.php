<?php

namespace App\Http\Resources\Management;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-19 — a recent-orders row on the customer detail screen, with the store
 * name and item count the legacy table rendered.
 */
final class CustomerParityOrderResource extends JsonResource
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
            'store' => $order->store?->name,
            'items_count' => (int) ($order->items_count ?? 0),
            'total' => (float) $order->total,
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'status_label' => $order->status_label,
            'source' => $order->source,
            'created_at' => $order->created_at?->toISOString(),
        ];
    }
}
