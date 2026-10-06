<?php

namespace App\Repositories\Management;

use App\Enums\TransactionStatus;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * WS-18 — query building for the transactions API.
 *
 * Scoping, filters and eager loads live here so the list, the CSV export, the
 * store filter options and the workflow's per-row store lookup all share one
 * definition. Reads and query building only: transaction boundaries and
 * abort() calls belong to services and controllers, not to this layer.
 */
class TransactionRepository
{
    /**
     * @var array<int, string>
     */
    public const LIST_RELATIONS = [
        'order:id,order_number,store_id,customer_id,meta',
        'order.store:id,name,store_id',
        'order.customer:id,first_name,last_name,email,phone',
        'invoice:id,invoice_number,store_id,recipient_name',
        'invoice.store:id,name,store_id',
        'paymentMethod',
    ];

    /**
     * @var array<int, string>
     */
    public const DETAIL_RELATIONS = [
        'order.store',
        'order.customer',
        'order.items',
        'order.staff',
        'invoice.store',
        'paymentMethod',
        'storeBank',
    ];

    /**
     * The list/export query: tenant scoping, legacy filters, eager loads and
     * the inherited newest-first ordering.
     *
     * @param  array<string, mixed>  $filters
     */
    public function listQuery(User $user, array $filters): Builder
    {
        return $this->applyFilters($this->scopedQuery($user), $filters)
            ->with(self::LIST_RELATIONS)
            ->latest();
    }

    /**
     * Pending payments visible to the user — the number the sidebar badge
     * renders.
     */
    public function pendingCount(User $user): int
    {
        return $this->scopedQuery($user)
            ->where('status', TransactionStatus::PENDING->value)
            ->count();
    }

    /**
     * Store filter options, scoped exactly like the rows: a store-assigned
     * staff member must not be offered a store their list can never return.
     *
     * @return Collection<int, Store>
     */
    public function storeFilterOptions(User $user): Collection
    {
        $query = $user->isStaff()
            ? Store::query()->whereIn('id', $this->staffStoreIds($user))
            : $user->accessibleStores();

        return $query
            ->where('status', '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            ->get();
    }

    /**
     * Stores a staff member may see transactions for.
     *
     * The legacy `isRestrictedStaff()` flag keyed on the transactions-view
     * permission — the very permission that guards these routes — so the
     * store scoping it implied could never engage. An explicit store
     * assignment is the reachable (and correct) definition of "restricted",
     * so it wins over the permission heuristic; staff with no assignments
     * fall back to their accessible stores.
     *
     * @return Collection<int, int>
     */
    public function staffStoreIds(User $user): Collection
    {
        if ($user->assignedStores()->exists()) {
            return $user->assignedStores()->pluck('stores.id')->map(fn ($id) => (int) $id);
        }

        return $user->accessibleStoreIds()->map(fn ($id) => (int) $id);
    }

    /**
     * The store a transaction's money belongs to: its order's store, or its
     * invoice's store.
     */
    public function storeFor(Transaction $transaction): ?Store
    {
        return $transaction->order?->store ?? $transaction->invoice?->store;
    }

    /**
     * Eager-load the detail read model onto a transaction for the show/action
     * responses.
     */
    public function loadDetail(Transaction $transaction): Transaction
    {
        return $transaction->load(self::DETAIL_RELATIONS);
    }

    private function scopedQuery(User $user): Builder
    {
        $query = Transaction::query()->where('business_id', $user->business_id);

        if ($user->isStaff()) {
            $storeIds = $this->staffStoreIds($user);

            $query->where(function ($scope) use ($storeIds) {
                $scope->whereHas('order', fn ($order) => $order->whereIn('store_id', $storeIds))
                    ->orWhereHas('invoice', fn ($invoice) => $invoice->whereIn('store_id', $storeIds));
            });
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        $reference = $filters['reference'] ?? $filters['q'] ?? null;

        return $query
            ->when($reference, fn ($q, $term) => $q->where('reference', 'like', '%'.$term.'%'))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->where(function ($scope) use ($storeId) {
                $scope->whereHas('order', fn ($order) => $order->where('store_id', $storeId))
                    ->orWhereHas('invoice', fn ($invoice) => $invoice->where('store_id', $storeId));
            }))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
    }
}
