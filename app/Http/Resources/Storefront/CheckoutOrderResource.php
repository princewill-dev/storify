<?php

namespace App\Http\Resources\Storefront;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The checkout order payload — the controller's orderPayload() map moved
 * verbatim: field names, order and types unchanged.
 *
 * The store block comes from the resolved storefront the controller already
 * holds, not from the order's relation, exactly as the inline map did. The
 * base shape stops at created_at; CheckoutOrderDetailResource appends the
 * customer, items and transactions for the order-tracking read without
 * touching this key order — so, like Management\OrderResource, this class is
 * deliberately not final.
 *
 * The caller passes the order; this class issues no queries of its own (the
 * payment_status accessor's query is the model's, as before).
 */
class CheckoutOrderResource extends JsonResource
{
    public function __construct(Order $order, private readonly Store $store)
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
            'order_number' => $order->order_number,
            'total' => (float) $order->total,
            'amount_paid' => (float) $order->amount_paid,
            'remaining' => (float) $order->remainingBalance(),
            'shipping_fee' => (float) $order->shipping_fee,
            'tax' => (float) $order->tax,
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'payment_status' => $order->payment_status?->value,
            'store' => ['name' => $this->store->name, 'slug' => $this->store->slug],
            'created_at' => $order->created_at?->toISOString(),
        ];
    }
}
