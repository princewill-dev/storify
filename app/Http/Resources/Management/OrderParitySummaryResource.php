<?php

namespace App\Http\Resources\Management;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-13 — the order board row (and the base every detail payload spreads).
 *
 * `transactions_count` / `items_count` come from the list query's withCount;
 * when a caller renders a row without those counters loaded (the detail
 * screen) the loaded relation's count is the fallback, so the payload shape
 * does not depend on how the order was fetched.
 */
final class OrderParitySummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        // First by id — legacy treated the oldest transaction as the payment
        // gate, and an unordered relation is not guaranteed to give it.
        $firstTransaction = $order->transactions->sortBy('id')->first();
        $legs = (int) ($order->transactions_count ?? $order->transactions->count());

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'customer' => $this->customer($order),
            'store' => $order->store?->name,
            'store_id' => $order->store_id,
            'source' => $order->source,
            'is_pos' => $order->isPos(),
            'items_count' => (int) ($order->items_count ?? $order->items->count()),
            'total' => (float) $order->total,
            'amount_paid' => (float) $order->amount_paid,
            'remaining' => $order->remainingBalance(),
            'payment_method' => $this->paymentMethodLabel($firstTransaction),
            'payment_method_code' => $firstTransaction?->paymentMethod?->code,
            'payment_legs' => $legs,
            'is_split_payment' => $legs > 1,
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'status_label' => $order->status_label,
            'created_at' => $order->created_at?->toISOString(),
        ];
    }

    /**
     * The customer block both screens render: a real customer for online
     * orders, the order meta for POS walk-ins (legacy hid the placeholder
     * `walkin@pos.local` account and fell back to the captured name/phone).
     *
     * @return array<string, mixed>
     */
    protected function customer(Order $order): array
    {
        $customer = $order->customer;
        $meta = $order->meta ?? [];

        $email = $customer?->email;
        $isWalkIn = ! $customer
            || ! $email
            || str_contains($email, 'walkin@pos.local')
            || str_contains($email, '@walkin.local');

        if ($isWalkIn) {
            return [
                'id' => $customer?->id,
                'name' => $meta['customer_name'] ?? ($customer?->first_name ? $customer->full_name : 'Walk-in'),
                'email' => null,
                'phone' => $meta['customer_phone'] ?? $customer?->phone,
                'is_walk_in' => true,
            ];
        }

        return [
            'id' => $customer->id,
            'name' => $customer->full_name,
            'email' => $email,
            'phone' => $customer->phone,
            'is_walk_in' => false,
        ];
    }

    protected function paymentMethodLabel(?Transaction $transaction): ?string
    {
        $method = $transaction?->paymentMethod;

        if (! $method) {
            return null;
        }

        return match ($method->code) {
            'cash' => 'Cash',
            'bank_transfer' => 'Transfer',
            default => $method->name,
        };
    }
}
