<?php

namespace App\Repositories\Management\Concerns;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Who may see which customers, and which of their orders, expressed once.
 *
 * WS-19 and WS-56 both read customers and both must answer the same question
 * before they can: a business owner and their unrestricted staff see the whole
 * book, and restricted staff see only the customers who have ordered from the
 * stores they are assigned to. That rule was written once inside
 * CustomerParityRepository; copying it into the WS-56 repository would mean two
 * implementations of a privacy boundary, and the weaker one silently wins.
 *
 * So it lives here and both use it. `applyCountryFilter` is here for a related
 * reason: the Messages audience is composed *after* the sender has filtered the
 * customer list, and a broadcast that resolves its country filter differently
 * from the list that displayed it would reach people the sender never saw.
 *
 * Methods are protected: this is inherited, not a service.
 */
trait ScopesCustomerReads
{
    /**
     * Business-wide for owners and their non-restricted staff; assigned stores
     * only for restricted staff, who could otherwise enumerate every customer
     * of the business.
     *
     * @param  Collection<int, int>  $storeIds
     * @return Builder<Customer>
     */
    protected function scopedQuery(User $user, Collection $storeIds): Builder
    {
        return Customer::query()
            ->where('business_id', $user->business_id)
            ->when($user->isRestrictedStaff(), fn ($q) => $q->whereHas(
                'orders',
                fn ($orders) => $orders->whereIn('store_id', $storeIds)
            ));
    }

    /**
     * The order-count sub-scope, for a `withCount` that must not count orders
     * from stores the reader cannot see.
     *
     * @param  Builder<Order>  $query
     * @param  Collection<int, int>  $storeIds
     * @return Builder<Order>
     */
    protected function scopeOrders(Builder $query, User $user, Collection $storeIds): Builder
    {
        return $user->isRestrictedStaff() ? $query->whereIn('store_id', $storeIds) : $query;
    }

    /**
     * One customer's orders, scoped for the reader.
     *
     * Named `ordersFor` rather than `ordersQuery` because the parity repository
     * already had a private method of that name returning the same thing, and
     * two methods with one name across a trait boundary is how a private one
     * quietly stops being called.
     *
     * Note the scope is re-derived from `$user` rather than read off the
     * customer: restricted staff and owners share the customer row, and the
     * difference between them is the reader, not the subject.
     */
    protected function ordersFor(Customer $customer, User $user): HasMany
    {
        $query = $customer->orders();

        if ($user->isRestrictedStaff()) {
            $query->whereIn('store_id', $user->accessibleStoreIds());
        }

        return $query;
    }

    /**
     * Free text across the identifying columns, including the composed name —
     * legacy matched "John Smith" against a single input box and the base API
     * dropped that, breaking the search that people actually type.
     *
     * @param  Builder<Customer>  $query
     */
    protected function applySearch(Builder $query, string $term): void
    {
        $like = '%'.$term.'%';

        $query->where(function ($inner) use ($like) {
            $inner->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('account_id', 'like', $like)
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
        });
    }

    /**
     * The country filter has two sources: the address columns on the customer
     * (what the legacy address card actually rendered) and the delivery-route
     * country the legacy filter used. Both, so every option in the dropdown
     * filters at least one row.
     *
     * @param  Builder<Customer>  $query
     */
    protected function applyCountryFilter(Builder $query, string $country): void
    {
        $query->where(fn ($inner) => $inner
            ->where('country', $country)
            ->orWhereHas('deliveryAddresses.deliveryRoute', fn ($route) => $route->where('country', $country)));
    }
}
