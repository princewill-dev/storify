<?php

namespace App\Http\Resources\Admin\Dashboard;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS7 — one row of the recent-orders feed (ten latest, platform-wide like
 * legacy's `$stats['recent_orders']`).
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
            'total' => (float) $order->total,
            'status' => $order->status instanceof OrderStatus
                ? $order->status->value
                : (string) $order->status,
            'created_at' => $order->created_at?->toISOString(),
        ];
    }
}
