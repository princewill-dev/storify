<?php

namespace App\Http\Resources\Storefront;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One account order-list row — the controller's inline map moved verbatim:
 * field names, order and types unchanged. The store relation is the one
 * AccountRepository::ordersQuery eager-loads.
 */
final class AccountOrderResource extends JsonResource
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
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'created_at' => $order->created_at?->toISOString(),
        ];
    }
}
