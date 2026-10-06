<?php

namespace App\Repositories\Management;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * WS-12 — reads for the order fulfilment console and its guarded transitions.
 *
 * Query building only: the console's eager loads, the activity feed, the
 * transaction lookups the accept/void/create branches need, the delivery
 * agent picker, the return's stock location and the manual-payment fallback.
 *
 * Transaction boundaries and writes deliberately stay out: the fulfilment
 * service owns the DB::transaction calls, and nothing in this layer aborts an
 * HTTP request.
 */
final class OrderFulfilmentRepository
{
    /**
     * Eager-load every relation the console payload renders.
     */
    public function loadConsole(Order $order): Order
    {
        $order->loadMissing([
            'items.product.images',
            'customer',
            'store',
            'delivery',
            'deliveryAddress',
            'deliveryRoute',
            'transactions.paymentMethod',
            'transactions.storeBank',
        ]);

        return $order;
    }

    /**
     * The console's activity timeline: newest first, capped at 50, with the
     * acting user's name.
     *
     * @return Collection<int, ActivityLog>
     */
    public function recentActivity(Order $order): Collection
    {
        return ActivityLog::query()
            ->where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->with('user:id,name')
            ->latest()
            ->limit(50)
            ->get();
    }

    /**
     * The payment gate's transaction: legacy treated the oldest as the one
     * that blocks acceptance.
     */
    public function firstTransaction(Order $order): ?Transaction
    {
        return $order->transactions()->orderBy('id')->first();
    }

    /**
     * The transaction a payment-status update targets: the newest one.
     */
    public function latestTransaction(Order $order): ?Transaction
    {
        return $order->transactions()->orderByDesc('id')->first();
    }

    /**
     * @return Collection<int, User>
     */
    public function activeDeliveryAgents(Order $order): Collection
    {
        return User::query()
            ->where('business_id', $order->business_id)
            ->where('status', 'active')
            ->role('Delivery Agent')
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'email']);
    }

    public function findDeliveryAgent(Order $order, int $agentId): ?User
    {
        return User::query()
            ->whereKey($agentId)
            ->where('business_id', $order->business_id)
            ->where('status', 'active')
            ->role('Delivery Agent')
            ->first();
    }

    /**
     * The order's store stock location for a product — the return workflow's
     * restoration target.
     */
    public function stockLocationFor(Order $order, int|string $productId): ?StockLocation
    {
        return StockLocation::query()
            ->where('locationable_type', Store::class)
            ->where('locationable_id', $order->store_id)
            ->where('product_id', $productId)
            ->first();
    }

    /**
     * Legacy created manual payments with the cash method and fell back to
     * the first payment method row; preserved exactly.
     */
    public function manualPaymentMethod(): ?PaymentMethod
    {
        return PaymentMethod::where('code', 'cash')->first()
            ?? PaymentMethod::query()->first();
    }
}
