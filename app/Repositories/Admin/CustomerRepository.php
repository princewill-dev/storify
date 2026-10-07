<?php

namespace App\Repositories\Admin;

use App\Enums\TransactionStatus;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * WS-9 (admin console) — platform customer directory, detail-console reads
 * and the country options.
 *
 * This layer builds queries and applies filters; it never opens a transaction
 * and never calls abort() — transactions belong to
 * CustomerModerationService, and the controller owns the HTTP status each
 * guard refusal maps to. The detail console's raw blocks are assembled here so
 * response shaping (CustomerConsoleResource) issues no queries of its own.
 *
 * The console is deliberately tenant-unaware: a platform admin sees every
 * business's customers, which is the point of the surface.
 */
final class CustomerRepository
{
    /**
     * The platform customer directory. Legacy's search, status and country
     * filters over the whitelisted sort the FormRequest guarantees.
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateForDirectory(array $filters): LengthAwarePaginator
    {
        return Customer::query()
            ->with(['business:id,name,business_code'])
            ->withCount('orders')
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $this->applySearch($q, $term))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', strtoupper($status)))
            ->when($filters['country'] ?? null, fn (Builder $q, string $country) => $this->applyCountryFilter($q, $country))
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();
    }

    /**
     * Platform-wide stat cards. `total` includes suspended and deleted
     * accounts (the directory lists them); `orders` counts live orders only,
     * where legacy's raw table count also counted soft-deleted rows.
     *
     * @return array<string, int>
     */
    public function stats(): array
    {
        $counts = Customer::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => (int) $counts->sum(),
            'active' => (int) ($counts[Customer::STATUS_ACTIVE] ?? 0),
            'suspended' => (int) ($counts[Customer::STATUS_SUSPENDED] ?? 0),
            'orders' => Order::query()->count(),
        ];
    }

    /**
     * The country filter's options.
     *
     * Two sources, same as the management parity screen: the address columns
     * on the customer (what the legacy address card rendered) and the
     * delivery-route country the legacy filter joined. Cached for ten minutes
     * instead of running the join per request; the customer count changes far
     * slower than the page is loaded.
     *
     * @return array<int, string>
     */
    public function countryOptions(): array
    {
        return Cache::remember('admin.customer_countries', now()->addMinutes(10), function () {
            $fromCustomers = Customer::query()
                ->whereNotNull('country')
                ->where('country', '!=', '')
                ->distinct()
                ->orderBy('country')
                ->pluck('country');

            $fromRoutes = DB::table('delivery_routes')
                ->whereNotNull('country')
                ->where('country', '!=', '')
                ->distinct()
                ->orderBy('country')
                ->pluck('country');

            return $fromCustomers->merge($fromRoutes)->unique()->sort()->values()->all();
        });
    }

    /**
     * The raw rows behind the detail console's blocks, in the order the
     * console has always assembled them. Shaping happens in
     * CustomerConsoleResource.
     *
     * @return array{
     *     stats: array{total_orders: int, completed_orders: int, pending_orders: int, total_spent: float},
     *     recent_orders: Collection<int, Order>,
     *     transactions: Collection<int, Transaction>,
     *     default_delivery_address: DeliveryAddress|null,
     *     activity: Collection<int, ActivityLog>
     * }
     */
    public function detailBlocks(Customer $customer): array
    {
        return [
            'stats' => $this->detailStats($customer),
            'recent_orders' => $this->recentOrders($customer),
            'transactions' => $this->recentTransactions($customer),
            'default_delivery_address' => $this->defaultDeliveryAddress($customer),
            'activity' => $this->activityFeed($customer),
        ];
    }

    /**
     * The detail tiles' aggregates.
     *
     * Aggregated in SQL: the money column is summed as a decimal, never
     * accumulated in PHP. Legacy's spend basis keeps: the order total only
     * counts when a confirmed transaction backs it.
     *
     * @return array{total_orders: int, completed_orders: int, pending_orders: int, total_spent: float}
     */
    public function detailStats(Customer $customer): array
    {
        $aggregate = $customer->orders()->selectRaw("
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders
        ")->first();

        $totalSpent = $customer->orders()
            ->whereHas('transactions', fn ($query) => $query->where('status', TransactionStatus::CONFIRMED->value))
            ->sum('total');

        return [
            'total_orders' => (int) ($aggregate->total_orders ?? 0),
            'completed_orders' => (int) ($aggregate->completed_orders ?? 0),
            'pending_orders' => (int) ($aggregate->pending_orders ?? 0),
            'total_spent' => (float) $totalSpent,
        ];
    }

    /**
     * The detail console's last ten orders, newest first, with the store
     * name and item count the legacy table rendered.
     *
     * @return Collection<int, Order>
     */
    public function recentOrders(Customer $customer, int $limit = 10): Collection
    {
        return $customer->orders()
            ->with(['store:id,name'])
            ->withCount('items')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * The detail console's last ten transactions across the customer's
     * orders, with the payment method and order number the legacy table
     * rendered.
     *
     * @return Collection<int, Transaction>
     */
    public function recentTransactions(Customer $customer, int $limit = 10): Collection
    {
        return Transaction::query()
            ->whereIn('order_id', $customer->orders()->select('orders.id'))
            ->with(['paymentMethod:id,name', 'order:id,order_number'])
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * The default delivery address (what the storefront actually ships to),
     * surfaced alongside the customer-table address columns.
     */
    public function defaultDeliveryAddress(Customer $customer): ?DeliveryAddress
    {
        return $customer->deliveryAddresses()
            ->orderByDesc('is_default')
            ->latest()
            ->first();
    }

    /**
     * The audit feed the legacy detail page rendered.
     *
     * @return Collection<int, ActivityLog>
     */
    public function activityFeed(Customer $customer, int $limit = 20): Collection
    {
        return ActivityLog::query()
            ->where('subject_type', Customer::class)
            ->where('subject_id', $customer->id)
            ->with('user:id,name')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * The business columns every customer payload reads; loaded here so the
     * resources issue no queries of their own.
     */
    public function loadDetailRelations(Customer $customer): Customer
    {
        return $customer->loadMissing('business:id,name,business_code');
    }

    /**
     * Legacy's search: name (including the composed full name), e-mail, phone
     * and account id.
     *
     * @param  Builder<Customer>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.trim($term).'%';

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
     * The country filter matches what the detail card shows (the customer
     * address columns) and the delivery-route country legacy filtered on.
     *
     * @param  Builder<Customer>  $query
     */
    private function applyCountryFilter(Builder $query, string $country): void
    {
        $query->where(fn ($inner) => $inner
            ->where('country', $country)
            ->orWhereHas('deliveryAddresses.deliveryRoute', fn ($route) => $route->where('country', $country)));
    }
}
