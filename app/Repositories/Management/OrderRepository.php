<?php

namespace App\Repositories\Management;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reads for the base orders API.
 *
 * The list query — business scope narrowed to the stores the user can reach,
 * the legacy filters, the eager loads and the newest-first ordering — and the
 * show screen's eager loads live here. Reads and query building only: the
 * status / payment-status writes change single rows and stay with the
 * controller, and nothing in this layer aborts an HTTP request.
 *
 * The list contract is deliberately kept as-is even though WS-17 re-registers
 * GET orders against Pos\OrderSourceController and WS-13 serves the SPA its
 * deeper orders/board/list slice; both carry their own supersets, and the
 * base payload other consumers read must not drift.
 */
final class OrderRepository
{
    /**
     * The show screen's relations.
     *
     * @var array<int, string>
     */
    public const DETAIL_RELATIONS = [
        'items',
        'customer',
        'store',
        'transactions.paymentMethod',
        'deliveryAddress',
    ];

    /**
     * The list query: business-scoped, filtered, eager-loaded and newest
     * first.
     *
     * The caller decides presence with filled() and passes the extracted
     * values; a null means "not filled" and skips the filter. The strict null
     * checks matter: a filled but non-numeric store_id yields the integer 0
     * and must still filter on it, exactly as the inline when() did — a
     * truthy check would silently drop that filter.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listQuery(User $user, array $filters): Builder
    {
        return Order::query()
            ->where('business_id', $user->business_id)
            ->whereIn('store_id', $user->accessibleStoreIds())
            ->when(($filters['store_id'] ?? null) !== null, fn ($q) => $q->where('store_id', $filters['store_id']))
            ->when(($filters['status'] ?? null) !== null, fn ($q) => $q->where('status', $filters['status']))
            ->when(($filters['from'] ?? null) !== null, fn ($q) => $q->whereDate('created_at', '>=', $filters['from']))
            ->when(($filters['to'] ?? null) !== null, fn ($q) => $q->whereDate('created_at', '<=', $filters['to']))
            ->when(($filters['q'] ?? null) !== null, function ($q) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $q->where(fn ($inner) => $inner->where('order_number', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('first_name', 'like', $term)
                        ->orWhere('last_name', 'like', $term)
                        ->orWhere('email', 'like', $term)));
            })
            ->with(['customer:id,first_name,last_name,email', 'store:id,name'])
            ->latest();
    }

    /**
     * Eager-load every relation the detailed payload renders.
     */
    public function loadDetail(Order $order): Order
    {
        return $order->load(self::DETAIL_RELATIONS);
    }
}
