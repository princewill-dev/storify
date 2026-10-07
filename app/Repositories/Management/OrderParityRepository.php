<?php

namespace App\Repositories\Management;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * WS-13 — order board reads for the parity endpoints.
 *
 * Scoping, filters and eager loads live here so the list and the status
 * counters share one definition; the detail relation set and its activity
 * timeline sit beside them. Query building only: transaction boundaries and
 * abort() calls belong to services and controllers, not to this layer.
 */
final class OrderParityRepository
{
    /**
     * The board list: tenant scoping, the legacy filters, the counted and
     * eager-loaded relations the summary resource renders, and newest-first
     * ordering.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listQuery(User $user, array $filters): Builder
    {
        return $this->scopedQuery($user)
            ->withCount(['items', 'transactions'])
            ->with([
                'customer:id,first_name,last_name,email,phone',
                'store:id,name',
                'transactions.paymentMethod:id,name,code',
            ])
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->where('store_id', $storeId))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.$term.'%';
                $q->where(fn ($inner) => $inner->where('order_number', 'like', $like)
                    ->orWhereHas('customer', fn ($c) => $c->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like)));
            })
            ->latest();
    }

    /**
     * Per-status counts for the board counters — the same tenant scope as the
     * list, so the two can never disagree.
     *
     * @return Collection<string, int>
     */
    public function statusCounts(User $user): Collection
    {
        return $this->scopedQuery($user)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
    }

    /**
     * Eager-load every relation the detail payload renders.
     */
    public function loadDetail(Order $order): Order
    {
        $order->loadMissing([
            'items.product.images',
            'customer',
            'store',
            'staff',
            'posSession',
            'deliveryAddress',
            'deliveryRoute',
            'delivery',
            'transactions.paymentMethod',
            'transactions.storeBank',
        ]);

        return $order;
    }

    /**
     * The detail screen's activity timeline: newest first, capped at 50, with
     * the acting user's name.
     *
     * @return EloquentCollection<int, ActivityLog>
     */
    public function recentActivity(Order $order): EloquentCollection
    {
        return ActivityLog::query()
            ->where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->with('user:id,name')
            ->latest()
            ->limit(50)
            ->get();
    }

    private function scopedQuery(User $user): Builder
    {
        return Order::query()
            ->where('business_id', $user->business_id)
            ->whereIn('store_id', $user->accessibleStoreIds());
    }
}
