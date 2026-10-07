<?php

namespace App\Http\Resources\Management;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-13 — the order detail/edit payload.
 *
 * Everything the detail panels rendered: the summary row, totals, the item
 * list with product imagery, staff/POS context, delivery tracking, the
 * transaction ledger with its bank proof-of-payment block, and the activity
 * timeline. The relations are eager-loaded by OrderParityRepository and the
 * timeline is handed in by the controller, so this class only shapes.
 */
final class OrderParityDetailResource extends JsonResource
{
    /**
     * @param  Collection<int, ActivityLog>  $activity
     */
    public function __construct(Order $order, private readonly Collection $activity)
    {
        parent::__construct($order);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return [
            ...(new OrderParitySummaryResource($order))->resolve($request),
            'subtotal' => (float) $order->subtotal,
            'shipping_fee' => (float) $order->shipping_fee,
            'tax' => (float) $order->tax,
            'service_charge' => $this->serviceCharge($order),
            'notes' => $order->notes,
            // Computed from the transactions relation, so it belongs on the
            // detailed payload where that relation is loaded — putting it on
            // every list row would fire a query per order.
            'payment_status' => $order->payment_status?->value,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_code' => $item->product_code,
                'product_image_url' => $item->product?->primaryImage()?->path
                    ? asset('storage/'.$item->product->primaryImage()->path)
                    : null,
                'unit_price' => (float) $item->unit_price,
                'quantity' => (int) $item->quantity,
                'subtotal' => (float) $item->subtotal,
                'is_digital' => (bool) $item->is_digital,
            ])->values()->all(),
            'staff' => $order->staff ? [
                'id' => $order->staff->id,
                'name' => $order->staff->name,
                'email' => $order->staff->email,
            ] : null,
            'pos_session' => $order->posSession ? [
                'id' => $order->posSession->id,
                'session_code' => $order->posSession->session_code,
            ] : null,
            'delivery_address' => $order->deliveryAddress ? [
                'name' => $order->deliveryAddress->recipient_name,
                'phone' => $order->deliveryAddress->recipient_phone,
                'street' => $order->deliveryAddress->street_address,
                'city' => $order->deliveryAddress->city,
                'state' => $order->deliveryAddress->state,
                'country' => $order->deliveryAddress->country,
            ] : null,
            // The stored state/area is the legacy fallback when an order has
            // no DeliveryAddress row and no matched route.
            'delivery_state' => $order->delivery_state,
            'delivery_area' => $order->delivery_area,
            'delivery_days' => $order->delivery_days !== null ? (int) $order->delivery_days : null,
            'delivery_route' => $order->deliveryRoute ? [
                'area' => $order->deliveryRoute->area,
                'state' => $order->deliveryRoute->state,
                // Kobo, as every other delivery-route payload returns it.
                // Legacy printed this column as naira, reading the fee 100×
                // too large; the SPA converts instead.
                'fee' => (int) $order->deliveryRoute->fee,
                'delivery_days' => $order->deliveryRoute->delivery_days,
            ] : null,
            'delivery' => $this->delivery($order->delivery),
            'transactions' => $order->transactions->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'status' => $transaction->status?->value,
                'status_label' => $transaction->status_label,
                'payment_method' => $transaction->paymentMethod?->name,
                'payment_method_code' => $transaction->paymentMethod?->code,
                'paid_at' => $transaction->paid_at?->toISOString(),
                'created_at' => $transaction->created_at?->toISOString(),
                'bank' => $transaction->storeBank ? [
                    'bank_name' => $transaction->storeBank->bank_name,
                    'account_number' => $transaction->storeBank->account_number,
                    'account_name' => $transaction->storeBank->account_name,
                    'is_verified' => (bool) $transaction->storeBank->is_verified,
                ] : null,
            ])->values()->all(),
            'activity' => $this->activity->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'user' => $log->user?->name,
                'created_at' => $log->created_at?->toISOString(),
                'created_at_human' => $log->created_at?->diffForHumans(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serviceCharge(Order $order): array
    {
        $meta = $order->meta ?? [];

        // Legacy fell back to the stored columns and then to whatever the
        // total left over after subtotal/shipping/tax.
        $amount = $order->service_charge_amount
            ?? ($meta['service_charge_amount'] ?? null)
            ?? (($order->total - $order->subtotal - $order->shipping_fee - $order->tax) > 0
                ? $order->total - $order->subtotal - $order->shipping_fee - $order->tax
                : 0);

        return [
            'name' => $meta['service_charge_name'] ?? null,
            'amount' => (float) $amount,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function delivery(?OrderDelivery $delivery): ?array
    {
        if (! $delivery) {
            return null;
        }

        return [
            'status' => $delivery->status,
            'tracking_number' => $delivery->tracking_number,
            'driver_name' => $delivery->driver_name,
            'driver_phone' => $delivery->driver_phone,
            'current_location' => $delivery->current_location,
            'recipient_name' => $delivery->recipient_name,
            'estimated_delivery_at' => $delivery->estimated_delivery_at?->toISOString(),
            'actual_delivery_at' => $delivery->actual_delivery_at?->toISOString(),
            'delivery_notes' => $delivery->delivery_notes,
            'return_reason' => $delivery->return_reason,
            'created_at' => $delivery->created_at?->toISOString(),
        ];
    }
}
