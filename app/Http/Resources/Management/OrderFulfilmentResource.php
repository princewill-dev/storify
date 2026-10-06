<?php

namespace App\Http\Resources\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Models\ActivityLog;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-12 — the fulfilment console payload.
 *
 * Everything the action bar, delivery tracker, payment panel and activity
 * timeline need in one call: the order, its items and totals, the actions the
 * current status unlocks, the payment-pending warning, delivery tracking, the
 * transaction list (with the bank proof-of-payment panel on the detailed
 * view) and the activity timeline.
 *
 * The order arrives with its relations eager-loaded by
 * OrderFulfilmentRepository, which also assembles the activity rows — response
 * shaping never issues its own queries beyond the assigned agent's name.
 */
final class OrderFulfilmentResource extends JsonResource
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

        $status = $order->status instanceof OrderStatus ? $order->status->value : $order->status;

        // Legacy treated the first transaction as the payment gate ("Payment
        // Pending" modal); the newest one is only the target of payment-status
        // updates. Keep both semantics explicit.
        $firstTransaction = $order->transactions->sortBy('id')->first();
        $pendingTransaction = $firstTransaction && $firstTransaction->status === TransactionStatus::PENDING
            ? $firstTransaction
            : null;

        $delivery = $order->delivery;

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $status,
            'status_label' => $order->status_label,
            'payment_status' => $order->payment_status?->value,
            'source' => $order->source,
            'is_pos' => $order->isPos(),
            'store' => $order->store ? [
                'id' => $order->store->id,
                'name' => $order->store->name,
            ] : null,
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'name' => $order->customer->full_name,
                'email' => $order->customer->email,
                'phone' => $order->customer->phone,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'product_code' => $item->product_code,
                'image_url' => $item->product?->primaryImage()
                    ? asset('storage/'.$item->product->primaryImage()->path)
                    : null,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
                'is_digital' => (bool) $item->is_digital,
            ])->values()->all(),
            'totals' => [
                'subtotal' => (float) $order->subtotal,
                'shipping_fee' => (float) $order->shipping_fee,
                'tax' => (float) $order->tax,
                'service_charge' => (float) ($order->service_charge_amount ?? 0),
                'total' => (float) $order->total,
                'amount_paid' => (float) $order->amount_paid,
                'remaining' => (float) $order->remainingBalance(),
            ],
            'notes' => $order->notes,
            'created_at' => $order->created_at?->toISOString(),
            'delivery_address' => $order->deliveryAddress ? [
                'name' => $order->deliveryAddress->recipient_name,
                'phone' => $order->deliveryAddress->recipient_phone,
                'street' => $order->deliveryAddress->street_address,
                'city' => $order->deliveryAddress->city,
                'state' => $order->deliveryAddress->state,
                'country' => $order->deliveryAddress->country,
            ] : null,
            'delivery_route' => $order->deliveryRoute ? [
                'id' => $order->deliveryRoute->id,
                'label' => $this->routeLabel($order->deliveryRoute),
                'fee' => (float) ($order->deliveryRoute->fee ?? 0),
            ] : null,
            'actions' => [
                'accept' => $status === OrderStatus::PENDING->value,
                'process' => $status === OrderStatus::ACCEPTED->value,
                'dispatch' => $status === OrderStatus::PROCESSING->value,
                'deliver' => $status === OrderStatus::DISPATCHED->value,
                'complete' => $status === OrderStatus::DELIVERED->value,
                'cancel' => in_array($status, [OrderStatus::PENDING->value, OrderStatus::ACCEPTED->value], true),
                'return' => in_array($status, [OrderStatus::DELIVERED->value, OrderStatus::COMPLETED->value], true),
            ],
            'payment_warning' => $pendingTransaction ? [
                'message' => 'This order has a payment still pending. Confirm it before accepting, or accept with an explicit override.',
                'transaction' => $this->transactionPayload($pendingTransaction, detailed: false),
            ] : null,
            'delivery' => $delivery ? [
                'id' => $delivery->id,
                'status' => $delivery->status,
                'tracking_number' => $delivery->tracking_number,
                'driver_name' => $delivery->driver_name,
                'driver_phone' => $delivery->driver_phone,
                'delivery_agent_id' => $delivery->delivery_agent_id !== null
                    ? (int) $delivery->delivery_agent_id
                    : null,
                'delivery_agent' => $delivery->delivery_agent_id
                    ? User::whereKey($delivery->delivery_agent_id)->value('name')
                    : null,
                'route' => $delivery->deliveryRoute ? $this->routeLabel($delivery->deliveryRoute) : null,
                'estimated_delivery_at' => $delivery->estimated_delivery_at?->toISOString(),
                'actual_delivery_at' => $delivery->actual_delivery_at?->toISOString(),
                'delivery_notes' => $delivery->delivery_notes,
                'return_reason' => $delivery->return_reason,
            ] : null,
            'transactions' => $order->transactions->sortBy('id')->values()
                ->map(fn (Transaction $transaction) => $this->transactionPayload($transaction, detailed: true))
                ->all(),
            'activity' => $this->activity
                ->map(fn (ActivityLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'user' => $log->user?->name,
                    'created_at' => $log->created_at?->toISOString(),
                ])->values()->all(),
        ];
    }

    private function routeLabel(DeliveryRoute $route): ?string
    {
        $label = collect([$route->area, $route->state])->filter()->implode(', ');

        return $label !== '' ? $label : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionPayload(Transaction $transaction, bool $detailed): array
    {
        $data = [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'status' => $transaction->status instanceof TransactionStatus
                ? $transaction->status->value
                : $transaction->status,
            'payment_method' => $transaction->paymentMethod?->name,
            'paid_at' => $transaction->paid_at?->toISOString(),
        ];

        if ($detailed) {
            // Bank proof-of-payment panel: staff match the transfer against
            // the receiving account (legacy's "Bank Account" card).
            $data['bank'] = $transaction->storeBank ? [
                'bank_name' => $transaction->storeBank->bank_name,
                'account_number' => $transaction->storeBank->account_number,
                'account_name' => $transaction->storeBank->account_name,
                'is_verified' => (bool) $transaction->storeBank->is_verified,
            ] : null;
        }

        return $data;
    }
}
