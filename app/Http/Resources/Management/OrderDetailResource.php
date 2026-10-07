<?php

namespace App\Http\Resources\Management;

use App\Enums\TransactionStatus;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Http\Request;

/**
 * The detailed order payload — the controller's payload() detailed variant,
 * moved verbatim: the parent's list row plus the totals, notes, items,
 * transactions and delivery address, appended in the order the array literal
 * emitted them so the serialised shape is unchanged.
 *
 * The caller (OrderController::show) passes the order eager-loaded by
 * OrderRepository::loadDetail; this class issues no queries of its own.
 */
final class OrderDetailResource extends OrderResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        $data = [
            ...parent::toArray($request),
            'subtotal' => (float) $order->subtotal,
            'shipping_fee' => (float) $order->shipping_fee,
            'tax' => (float) $order->tax,
            'service_charge' => (float) ($order->service_charge_amount ?? 0),
            'notes' => $order->notes,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'product_code' => $item->product_code,
                'unit_price' => (float) $item->unit_price,
                'quantity' => (int) $item->quantity,
                'subtotal' => (float) $item->subtotal,
                'is_digital' => (bool) $item->is_digital,
            ])->values()->all(),
            'transactions' => $order->transactions->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'amount' => (float) $transaction->amount,
                'status' => $transaction->status instanceof TransactionStatus ? $transaction->status->value : $transaction->status,
                'payment_method' => $transaction->paymentMethod?->name,
                'paid_at' => $transaction->paid_at?->toISOString(),
            ])->values()->all(),
            'delivery_address' => $order->deliveryAddress ? [
                'name' => $order->deliveryAddress->recipient_name,
                'phone' => $order->deliveryAddress->recipient_phone,
                'street' => $order->deliveryAddress->street_address,
                'city' => $order->deliveryAddress->city,
                'state' => $order->deliveryAddress->state,
                'country' => $order->deliveryAddress->country,
            ] : null,
        ];

        return $data;
    }
}
