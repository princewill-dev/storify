<?php

namespace App\Repositories\Management;

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * WS-19 — reads behind the store detail "Customers" tab.
 *
 * A store's buyers are the customers of the store's business with at least one
 * order in that store — the same predicate the tab's "unique buyers" metric
 * counts. It is built once here and shared by the page and its total, so the
 * rows and the count cannot drift apart. The business constraint is the
 * tenancy scope: the controller's store guard is the first line, this is the
 * second, and a store id alone would not stop another business's customers
 * leaking into the list.
 *
 * Query building only — no abort() calls and no transaction boundaries; the
 * controller owns the store guard and the HTTP contract.
 */
final class StoreCustomerRepository
{
    /**
     * The tab's list: buyers of the store, filtered, with the per-store order
     * count, newest first.
     *
     * `q` searches the columns the legacy tab searched, including the composed
     * full name. `status` arrives lowercased (the request normalises it) and
     * is uppercased here, where the schema's spelling is applied. The `when()`
     * guards keep the pre-refactor semantics: an absent or blank filter stays
     * unfiltered.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Customer>
     */
    public function paginateForStore(Store $store, array $filters): LengthAwarePaginator
    {
        return $this->buyersQuery($store)
            ->withCount(['orders as orders_count' => fn ($orders) => $orders->where('store_id', $store->id)])
            ->when($filters['q'] ?? null, function (Builder $query, string $term) {
                $like = '%'.$term.'%';

                $query->where(fn (Builder $inner) => $inner->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('account_id', 'like', $like)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]));
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', strtoupper($status)))
            ->latest('created_at')
            ->paginate($filters['per_page'] ?? 15)
            ->withQueryString();
    }

    /**
     * The payload's `customers_count`: the same buyers predicate as the list,
     * tenancy-scoped the same way, so the "unique buyers" number and the rows
     * below it always agree.
     */
    public function countBuyers(Store $store): int
    {
        return $this->buyersQuery($store)->count();
    }

    /**
     * Distinct buyers of one store: business-scoped and narrowed to customers
     * holding an order in this store.
     *
     * @return Builder<Customer>
     */
    private function buyersQuery(Store $store): Builder
    {
        return Customer::query()
            ->where('business_id', $store->business_id)
            ->whereHas('orders', fn ($orders) => $orders->where('store_id', $store->id));
    }
}
