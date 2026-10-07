<?php

namespace App\Http\Resources\Management;

use App\Enums\OrderStatus;
use App\Models\DeliveryRoute;
use App\Models\OrderDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-26 — one dispatch-board row: the columns the legacy table rendered plus
 * the linked order and store the row deeplinks to. The relations are
 * eager-loaded by DispatchRepository::paginateForUser(), so shaping here
 * issues no queries of its own.
 */
final class DispatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OrderDelivery $delivery */
        $delivery = $this->resource;
        $order = $delivery->order;
        $status = $order?->status instanceof OrderStatus ? $order->status->value : $order?->status;

        return [
            'id' => $delivery->id,
            'status' => $delivery->status,
            'status_label' => self::label($delivery->status),
            'order' => $order ? [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $status,
                'status_label' => $order->status_label,
                'is_pos' => $order->isPos(),
                'customer_name' => $order->customer?->full_name,
                'total' => (float) $order->total,
            ] : null,
            'store' => $order?->store ? [
                'id' => $order->store->id,
                'name' => $order->store->name,
            ] : null,
            'driver_name' => $delivery->driver_name,
            'driver_phone' => $delivery->driver_phone,
            'tracking_number' => $delivery->tracking_number,
            'current_location' => $delivery->current_location,
            'route' => $delivery->deliveryRoute ? self::routeLabel($delivery->deliveryRoute) : null,
            'estimated_delivery_at' => $delivery->estimated_delivery_at?->toISOString(),
            'actual_delivery_at' => $delivery->actual_delivery_at?->toISOString(),
            'delivery_notes' => $delivery->delivery_notes,
            'return_reason' => $delivery->return_reason,
            'created_at' => $delivery->created_at?->toISOString(),
        ];
    }

    /**
     * Legacy's badge text: `ucfirst(str_replace('_', ' ', $status))`. Shared
     * with the board's status filter options so the two cannot drift.
     */
    public static function label(string $status): string
    {
        return ucfirst(str_replace('_', ' ', $status));
    }

    private static function routeLabel(DeliveryRoute $route): ?string
    {
        $label = collect([$route->area, $route->state])->filter()->implode(', ');

        return $label !== '' ? $label : null;
    }
}
