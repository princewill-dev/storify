<?php

namespace App\Repositories\Management;

use App\Models\Customer;
use App\Models\CustomerConsent;
use App\Models\CustomerMessage;
use App\Models\CustomerNote;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Management\Concerns\ScopesCustomerReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * WS-56 — the read model behind the Customers section's tabs and its two
 * queues (messages, deletion requests).
 *
 * Companion to CustomerParityRepository rather than an extension of it: that
 * one owns the list, the stat cards and the detail screen's summary blocks,
 * which WS-19 shipped and other workstreams consume. This one owns everything
 * that is paginated and opened on demand — a customer's orders, payments,
 * addresses, notes, messages, and the cross-customer deletion queue.
 *
 * The tenant scoping is not re-derived here. `scopedQuery()`, `ordersQuery()`
 * and the restricted-staff rule all come from ScopesCustomerReads, which is the
 * same trait the parity repository uses, so a cashier whose access is limited
 * to two stores sees the same rows whichever tab they are on. Re-deriving it
 * is how the two would drift.
 *
 * Read-only and query-building only. The three write paths (notes, messages,
 * erasure) are services, because each commits more than one row and needs a
 * transaction boundary the repository has no business owning.
 */
final class CustomerAccountRepository
{
    use ScopesCustomerReads;

    /**
     * A customer's orders, newest first, paginated by the caller.
     *
     * A relation rather than a query, because the scope that decides which of
     * them the reader may see is a filter on the relation itself (see
     * ScopesCustomerReads::ordersFor). It still paginates.
     *
     * @return HasMany<Order, Customer>
     */
    public function ordersQuery(Customer $customer, User $user): HasMany
    {
        return $this->ordersFor($customer, $user)
            ->with(['store:id,name'])
            ->withCount('items')
            ->latest();
    }

    /**
     * A customer's payment attempts, newest first.
     *
     * Reached through the orders rather than a column on the customer, because
     * that is what the relationship is: a transaction belongs to an order, and
     * the order belongs to the person. A customer with no orders has no
     * payments, which is correct and not an empty-join artefact.
     *
     * @return Builder<Transaction>
     */
    public function paymentsQuery(Customer $customer, User $user): Builder
    {
        return Transaction::query()
            ->whereIn('order_id', $this->ordersFor($customer, $user)->select('orders.id'))
            ->with(['paymentMethod:id,name', 'order:id,order_number'])
            ->latest();
    }

    /**
     * The customer's saved address book. Snapshots are deliberately absent:
     * one row is written per order ever placed, and a tab that listed them
     * would be a second, worse copy of the Orders tab.
     *
     * @return Builder<DeliveryAddress>
     */
    public function addressesQuery(Customer $customer): Builder
    {
        return DeliveryAddress::query()
            ->where('customer_id', $customer->id)
            ->where('is_snapshot', false)
            ->orderByDesc('is_default')
            ->latest('id');
    }

    /**
     * @return Builder<CustomerNote>
     */
    public function notesQuery(Customer $customer): Builder
    {
        return CustomerNote::query()
            ->where('customer_id', $customer->id)
            ->with('user:id,name')
            ->latest('id');
    }

    /**
     * Messages this business has sent this customer, newest first.
     *
     * @return Builder<CustomerMessage>
     */
    public function messagesQuery(Customer $customer): Builder
    {
        return CustomerMessage::query()
            ->where('customer_id', $customer->id)
            ->with('user:id,name')
            ->latest('id');
    }

    /**
     * The full consent trail, newest first — the same rows ConsentService
     * writes, listed rather than interpreted.
     *
     * @return Builder<CustomerConsent>
     */
    public function consentsQuery(Customer $customer): Builder
    {
        return CustomerConsent::query()
            ->where('customer_id', $customer->id)
            ->latest('id');
    }

    /**
     * The deletion queue: accounts whose owner asked for erasure and which have
     * not yet been actioned.
     *
     * Ordering is oldest first — a queue, not a list. The request nobody has
     * looked at longest is the one that should be at the top, and sorting by
     * newest is how a compliance queue quietly grows a backlog.
     *
     * Erased accounts cannot reappear: approval clears nothing on
     * `deletion_requested_at` (see CustomerErasureService), but the state it
     * sets is `deleted`, which is filtered out here as a second, independent
     * guard against an actioned request being actioned twice.
     *
     * @return Builder<Customer>
     */
    public function deletionRequestsQuery(User $user, Collection $storeIds): Builder
    {
        return $this->scopedQuery($user, $storeIds)
            ->whereNotNull('deletion_requested_at')
            ->where('status', '!=', Customer::STATUS_DELETED)
            ->oldest('deletion_requested_at');
    }

    /**
     * The audience for a broadcast: everyone the filters name, tenant-scoped
     * and already filtered to people a message may honestly be sent to.
     *
     * Exclusions are the point of this method, so they are listed rather than
     * left to the caller:
     *
     * - **Deleted** rows are tombstones and **suspended** rows are people the
     *   business has already decided not to deal with; mailing either is wrong
     *   on its face. Both are excluded regardless of what the filter says.
     * - **Anyone with a deletion request outstanding** is excluded: they have
     *   asked to be forgotten, and a marketing send while that request sits in
     *   the queue is the worst possible moment to mail them.
     * - **Marketing consent** is checked only when the message is marketing. A
     *   transactional message is one the person caused — an order, a receipt —
     *   and consent is not a gate on those. The check reads the newest row per
     *   customer and its `granted` value, matching ConsentService::state():
     *   a customer who has never been asked has no row, has therefore never
     *   opted in, and is left out.
     *
     * @param  Collection<int, int>  $storeIds
     * @param  array<string, mixed>  $filters
     * @return Builder<Customer>
     */
    public function audienceQuery(User $user, Collection $storeIds, array $filters, string $category): Builder
    {
        $query = $this->listScopedQuery($user, $storeIds, $filters)
            ->whereNotIn('status', [Customer::STATUS_DELETED, Customer::STATUS_SUSPENDED])
            ->whereNull('deletion_requested_at');

        if (CustomerMessage::requiresConsent($category)) {
            $query->whereExists(fn ($sub) => $sub
                ->from('customer_consents as cc')
                ->whereColumn('cc.customer_id', 'customers.id')
                ->where('cc.type', 'marketing')
                ->where('cc.granted', true)
                ->whereRaw('cc.id = (SELECT MAX(cc2.id) FROM customer_consents as cc2 WHERE cc2.customer_id = customers.id AND cc2.type = ?)', ['marketing']));
        }

        return $query->latest('customers.created_at');
    }

    /**
     * The CSV export: the filtered customer list, with the two aggregates the
     * export has to carry and the list does not.
     *
     * `lifetime_value` is summed in SQL as a decimal, never in PHP as a float,
     * and only over completed orders — the same basis the detail screen's tile
     * and `spend_basis` name, so an exported row and the screen it came from
     * agree. Both aggregates are scoped for a restricted-staff reader through
     * the same helper the counts use, or the export would sum orders from
     * stores the reader cannot open.
     *
     * @param  Collection<int, int>  $storeIds
     * @param  array<string, mixed>  $filters
     * @return Builder<Customer>
     */
    public function exportQuery(User $user, Collection $storeIds, array $filters): Builder
    {
        $scope = fn ($query) => $this->scopeOrders($query, $user, $storeIds);

        return $this->listScopedQuery($user, $storeIds, $filters)
            ->withCount(['orders as orders_count' => $scope])
            ->withSum(['orders as lifetime_value' => fn ($query) => $scope($query)->where('status', 'completed')], 'total')
            ->withMax(['orders as last_order_at' => $scope], 'created_at');
    }

    /**
     * Everything the filters and the customer list have in common, minus the
     * withCount the list needs and a broadcast does not.
     *
     * @param  Collection<int, int>  $storeIds
     * @param  array<string, mixed>  $filters
     * @return Builder<Customer>
     */
    private function listScopedQuery(User $user, Collection $storeIds, array $filters): Builder
    {
        return $this->scopedQuery($user, $storeIds)
            ->when($filters['q'] ?? null, fn ($q, $term) => $this->applySearch($q, $term))
            ->when($filters['account_state'] ?? null, fn ($q, $state) => $q->accountState($state))
            ->when($filters['country'] ?? null, fn ($q, $country) => $this->applyCountryFilter($q, $country))
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->whereHas(
                'orders',
                fn ($orders) => $orders->where('store_id', $storeId)
            ));
    }
}
