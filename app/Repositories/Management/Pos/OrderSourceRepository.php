<?php

namespace App\Repositories\Management\Pos;

use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * WS-17 — the orders list behind the POS oversight module (the `GET orders`
 * takeover registered by routes/api/v1/management/ws17-pos-oversight.php).
 *
 * Query composition only: tenant scoping, the legacy filters, the eager loads
 * and the inherited newest-first ordering. This layer never opens a
 * transaction and never aborts an HTTP request; the list is a single-table
 * read with no side effect, so no service layer is involved.
 *
 * The scoping is load-bearing: the business clause and the accessible-store
 * clause each keep another tenant's orders off the list — getting either
 * wrong leaks data — which is what earns this composition a named place.
 * The filter values arrive already resolved by the controller with the same
 * filled()/integer()/date() semantics the inline query used.
 */
final class OrderSourceRepository
{
    /**
     * The filtered, paginated list: scoping, the source/store/status/date
     * filters, the search across order number and customer, the eager loads
     * and `latest()` — the inherited ordering, kept verbatim.
     *
     * @param  array{source?: string|null, store_id?: int|null, status?: string|null, from?: Carbon|null, to?: Carbon|null, q?: string|null, per_page?: int}  $filters
     */
    public function paginateForUser(User $user, array $filters): LengthAwarePaginator
    {
        return Order::query()
            ->where('business_id', $user->business_id)
            ->whereIn('store_id', $user->accessibleStoreIds())
            ->when(($filters['store_id'] ?? null) !== null, fn ($q) => $q->where('store_id', $filters['store_id']))
            ->when(($filters['status'] ?? null) !== null, fn ($q) => $q->where('status', $filters['status']))
            // Legacy filtered Source as an exact match with two options; the
            // POS badge reads the same column.
            ->when($filters['source'] ?? null, fn ($q, $source) => $q->where('source', $source))
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
            ->latest()
            ->paginate($filters['per_page'] ?? 20);
    }
}
