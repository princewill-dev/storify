<?php

namespace App\Repositories\Management;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Management\Concerns\ScopesCustomerReads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WS-19 — query building for the customers parity API.
 *
 * The tenant scope (business-wide for owners and their non-restricted staff,
 * assigned stores only for restricted staff), the legacy list filters, the
 * four stat cards, the two-source country options and the detail screen's
 * read blocks all live here. Reads and query building only: the transaction
 * boundary belongs to CustomerLifecycleService and abort() calls belong to
 * the controller.
 *
 * Store ids are resolved once by the controller and passed in, so a request
 * keeps the same `accessibleStoreIds()` query count it had before the move.
 * `detailBlocks()` is the exception: its order scoping only runs for
 * restricted staff, exactly as the controller's `ordersQuery()` did.
 */
final class CustomerParityRepository
{
    use ScopesCustomerReads;

    /**
     * The list query: tenant-scoped, filtered and newest first. The caller
     * paginates it.
     *
     * @param  Collection<int, int>  $storeIds
     * @param  array<string, mixed>  $filters
     * @return Builder<Customer>
     */
    public function listQuery(User $user, Collection $storeIds, array $filters): Builder
    {
        return $this->scopedQuery($user, $storeIds)
            ->withCount(['orders as orders_count' => fn ($q) => $this->scopeOrders($q, $user, $storeIds)])
            // Summed in SQL rather than in PHP, and on the same basis the
            // detail screen's tile uses — completed orders — so the list column
            // and the profile cannot disagree about what one customer is worth.
            // Scoped like the count: a restricted reader's lifetime value is
            // the value of the orders they can see.
            ->withSum(
                ['orders as lifetime_value' => fn ($q) => $this->scopeOrders($q, $user, $storeIds)->where('status', 'completed')],
                'total'
            )
            ->when($filters['q'] ?? null, fn ($q, $term) => $this->applySearch($q, $term))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', strtoupper($status)))
            ->when($filters['account_state'] ?? null, fn ($q, $state) => $q->accountState($state))
            ->when($filters['country'] ?? null, fn ($q, $country) => $this->applyCountryFilter($q, $country))
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->whereHas('orders', fn ($orders) => $orders->where('store_id', $storeId)))
            ->latest('created_at');
    }

    /**
     * The four stat cards, scoped exactly like the list.
     *
     * @param  Collection<int, int>  $storeIds
     * @return array<string, int>
     */
    public function stats(User $user, Collection $storeIds): array
    {
        $counts = $this->scopedQuery($user, $storeIds)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $orders = Order::query()
            ->where('business_id', $user->business_id)
            ->when($user->isRestrictedStaff(), fn ($q) => $q->whereIn('store_id', $storeIds));

        return [
            'total' => (int) $counts->sum(),
            'active' => (int) ($counts[Customer::STATUS_ACTIVE] ?? 0),
            'suspended' => (int) ($counts[Customer::STATUS_SUSPENDED] ?? 0),
            'total_orders' => (clone $orders)->count(),
        ];
    }

    /**
     * The country filter has two sources: the address columns on the customer
     * (what the legacy address card actually rendered) and the delivery-route
     * country the legacy filter used. Returning both means every option in the
     * dropdown filters at least one row.
     *
     * @param  Collection<int, int>  $storeIds
     * @return Collection<int, string>
     */
    public function countryOptions(User $user, Collection $storeIds): Collection
    {
        $fromCustomers = $this->scopedQuery($user, $storeIds)
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->distinct()
            ->orderBy('country')
            ->pluck('country');

        $fromRoutes = DB::table('delivery_routes')
            ->whereIn('store_id', $storeIds)
            ->whereNotNull('country')
            ->where('country', '!=', '')
            ->distinct()
            ->orderBy('country')
            ->pluck('country');

        return $fromCustomers->merge($fromRoutes)->unique()->sort()->values();
    }

    /**
     * The detail screen's raw blocks, in the order the controller has always
     * read them. Shaping happens in the CustomerParity resources.
     *
     * @return array{
     *     aggregate: object|null,
     *     recent_orders: Collection<int, Order>,
     *     transactions: Collection<int, Transaction>,
     *     activity: Collection<int, ActivityLog>
     * }
     */
    public function detailBlocks(Customer $customer, User $user): array
    {
        $orders = $this->ordersFor($customer, $user);

        // Aggregated in SQL so the money column is summed as a decimal, never
        // as a PHP float. The spend basis (completed orders) is named in the
        // payload because legacy had three different definitions of the tile.
        $aggregate = (clone $orders)->selectRaw("
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END), 0) as total_spent,
            MAX(created_at) as last_order_at
        ")->first();

        return [
            'aggregate' => $aggregate,
            'recent_orders' => (clone $orders)
                ->with(['store:id,name'])
                ->withCount('items')
                ->latest()
                ->limit(10)
                ->get(),
            'transactions' => Transaction::query()
                ->whereIn('order_id', (clone $orders)->select('orders.id'))
                ->with(['paymentMethod:id,name', 'order:id,order_number'])
                ->latest()
                ->limit(10)
                ->get(),
            'activity' => ActivityLog::query()
                ->where('subject_type', Customer::class)
                ->where('subject_id', $customer->id)
                ->with('user:id,name')
                ->latest()
                ->limit(20)
                ->get(),
        ];
    }

    // scopedQuery(), scopeOrders(), ordersFor(), applySearch() and
    // applyCountryFilter() moved to the ScopesCustomerReads trait so that WS-56
    // reads customers through the same privacy boundary as this one. Their
    // behaviour is unchanged; only their address is.
}
