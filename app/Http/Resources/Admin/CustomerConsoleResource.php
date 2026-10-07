<?php

namespace App\Http\Resources\Admin;

use App\Enums\OrderStatus;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * WS-9 (admin console) — the customer console payload.
 *
 * Everything the legacy detail page rendered on top of the directory row: the
 * four stat tiles, the info and address cards, the last ten orders and
 * transactions, and the audit feed. The raw rows and the aggregates arrive as
 * the blocks CustomerRepository::detailBlocks() reads, so response shaping
 * issues no queries of its own.
 *
 * `stats.spend_basis` is a consumed label, not a computed value — it stays
 * verbatim. `total_spent` is the stored decimal cast, not a kobo conversion:
 * routing it through `Naira` would change a consumed payload.
 */
final class CustomerConsoleResource extends CustomerDetailResource
{
    /**
     * @param  array{
     *     stats: array{total_orders: int, completed_orders: int, pending_orders: int, total_spent: float},
     *     recent_orders: Collection<int, Order>,
     *     transactions: Collection<int, Transaction>,
     *     default_delivery_address: DeliveryAddress|null,
     *     activity: Collection<int, ActivityLog>
     * }  $blocks
     */
    public function __construct(Customer $customer, private readonly array $blocks)
    {
        parent::__construct(
            $customer,
            $blocks['default_delivery_address'] ?? null,
            $blocks['stats']['total_orders'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'customer' => parent::toArray($request),
            'stats' => $this->stats(),
            'recent_orders' => $this->recentOrders(),
            'transactions' => $this->transactions(),
            'activity' => $this->activity(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        return [
            'total_orders' => $this->blocks['stats']['total_orders'],
            'completed_orders' => $this->blocks['stats']['completed_orders'],
            'pending_orders' => $this->blocks['stats']['pending_orders'],
            'total_spent' => $this->blocks['stats']['total_spent'],
            'spend_basis' => 'orders with confirmed transactions',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentOrders(): array
    {
        return $this->blocks['recent_orders']->map(fn (Order $order) => [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'store' => $order->store?->name,
            'items_count' => (int) ($order->items_count ?? 0),
            'total' => (float) $order->total,
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'status_label' => $order->status_label,
            'source' => $order->source,
            'created_at' => $order->created_at?->toISOString(),
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transactions(): array
    {
        return $this->blocks['transactions']->map(fn (Transaction $transaction) => [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
            'order_number' => $transaction->order?->order_number,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'status' => $transaction->status?->value,
            'status_label' => $transaction->status?->label(),
            'payment_method' => $transaction->paymentMethod?->name,
            'paid_at' => $transaction->paid_at?->toISOString(),
            'created_at' => $transaction->created_at?->toISOString(),
        ])->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activity(): array
    {
        return $this->blocks['activity']->map(fn (ActivityLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'user' => $log->user?->name,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'created_at' => $log->created_at?->toISOString(),
            'created_at_human' => $log->created_at?->diffForHumans(),
        ])->values()->all();
    }
}
