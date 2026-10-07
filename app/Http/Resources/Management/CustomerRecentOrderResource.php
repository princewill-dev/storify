<?php

namespace App\Http\Resources\Management;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recent-orders row on the customer detail screen. The enum check mirrors
 * the controller's inline map: the cast value is emitted as its backing
 * string, and an uncast value passes through unchanged.
 */
class CustomerRecentOrderResource extends JsonResource
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
            'created_at' => $order->created_at?->toISOString(),
        ];
    }
}
