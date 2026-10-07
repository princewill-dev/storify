<?php

namespace App\Http\Resources\Management\Dashboard;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-28 — one row of the recent-orders panel (the eight newest orders in
 * scope, with the item count the legacy panel showed).
 *
 * The status normaliser is the defensive one the controller carried: the row
 * may arrive with the enum already cast or as the raw column string.
 *
 * @property-read Order $resource
 */
final class RecentOrderResource extends JsonResource
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
            'store' => $order->store?->name,
            'items_count' => (int) $order->items_count,
            'total' => (float) $order->total,
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'created_at' => $order->created_at?->toISOString(),
        ];
    }
}
