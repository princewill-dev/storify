<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * WS-19 — customers module parity.
 *
 * Lives beside CustomerController (which it replaces on the shared routes)
 * because the base controller's list/show/update shape is consumed by other
 * workstreams and must not change. This one carries what the legacy screens
 * rendered and the base payloads dropped: country/store filters and the four
 * stat cards, account_id and full-name search, the deep-linkable detail screen
 * (pending count, address columns, transactions, activity timeline), status
 * editing with email-verification sync, and suspend/activate with a required,
 * persisted reason.
 *
 * Legacy fixes carried here deliberately:
 * - the actor is the real `user_id` (legacy stashed it in `metadata.user_id`);
 * - restricted staff are scoped to their assigned stores instead of legacy's
 *   per-current-user order counts, which made an owner and their cashier see
 *   different numbers for the same customer;
 * - `total_spent` is completed orders and says so (`spend_basis`), where three
 *   legacy definitions existed;
 * - the address block reads the customers-table columns, not the dead
 *   `deliveryAddresses` relation the legacy controllers loaded and never
 *   rendered.
 *
 * Business-vs-admin suspension e-mail decision: a business suspend/activate
 * writes the audit trail but does NOT e-mail the customer. The only
 * customer-facing account mailable is framed as platform administration
 * ("suspended by our administration team"), so reusing it for a
 * business-initiated action would misattribute the suspension to Storify. The
 * admin module (deferred) keeps the e-mails; a business-framed mail needs its
 * own template, which is net-new rather than parity.
 */
class CustomerParityController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        if ($request->filled('status')) {
            $request->merge(['status' => strtolower(trim((string) $request->input('status')))]);
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            // The business list carried Active/Suspended only; DELETED is an
            // admin-side filter in legacy and stays out of this list.
            'status' => ['nullable', Rule::in(['active', 'suspended'])],
            'country' => ['nullable', 'string', 'max:100'],
            'store_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $this->user($request);
        $storeIds = $this->accessibleStoreIds($request);

        if (isset($filters['store_id'])) {
            $this->authorizeStoreId($request, (int) $filters['store_id']);
        }

        $customers = $this->scopedQuery($user, $storeIds)
            ->withCount(['orders as orders_count' => fn ($q) => $this->scopeOrders($q, $user, $storeIds)])
            ->when($filters['q'] ?? null, fn ($q, $term) => $this->applySearch($q, $term))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', strtoupper($status)))
            ->when($filters['country'] ?? null, fn ($q, $country) => $this->applyCountryFilter($q, $country))
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->whereHas('orders', fn ($orders) => $orders->where('store_id', $storeId)))
            ->latest('created_at')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            [
                'customers' => $customers->getCollection()->map(fn (Customer $customer) => $this->summary($customer))->values()->all(),
                'stats' => $this->stats($user, $storeIds),
            ],
            null,
            200,
            $this->paginationMeta($customers),
        );
    }

    /**
     * The country filter has two sources: the address columns on the customer
     * (what the legacy address card actually rendered) and the delivery-route
     * country the legacy filter used. Returning both means every option in the
     * dropdown filters at least one row.
     */
    public function countries(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $storeIds = $this->accessibleStoreIds($request);

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

        $countries = $fromCustomers->merge($fromRoutes)->unique()->sort()->values();

        return $this->ok(['countries' => $countries->all()]);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $user = $this->user($request);
        $orders = $this->ordersQuery($customer, $user);

        // Aggregated in SQL so the money column is summed as a decimal, never
        // as a PHP float. The spend basis (completed orders) is named in the
        // payload because legacy had three different definitions of the tile.
        $aggregate = (clone $orders)->selectRaw("
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
            COALESCE(SUM(CASE WHEN status = 'completed' THEN total ELSE 0 END), 0) as total_spent
        ")->first();

        $recentOrders = (clone $orders)
            ->with(['store:id,name'])
            ->withCount('items')
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'store' => $order->store?->name,
                'items_count' => (int) ($order->items_count ?? 0),
                'total' => (float) $order->total,
                'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
                'status_label' => $order->status_label,
                'source' => $order->source,
                'created_at' => $order->created_at?->toISOString(),
            ])->values()->all();

        $transactions = Transaction::query()
            ->whereIn('order_id', (clone $orders)->select('orders.id'))
            ->with(['paymentMethod:id,name', 'order:id,order_number'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'reference' => $transaction->reference,
                'order_number' => $transaction->order?->order_number,
                'amount' => (float) $transaction->amount,
                'currency' => $transaction->currency,
                'status' => $transaction->status?->value,
                'status_label' => $transaction->status_label,
                'payment_method' => $transaction->paymentMethod?->name,
                'paid_at' => $transaction->paid_at?->toISOString(),
                'created_at' => $transaction->created_at?->toISOString(),
            ])->values()->all();

        // The edit modal's account summary reads `orders_count` off this
        // payload; without it the row renders as 0 for the very customer the
        // list counted correctly. Reuse the aggregate above so the count keeps
        // the same scoping as `stats.total_orders` and the list's withCount.
        $customer->setAttribute('orders_count', (int) ($aggregate->total_orders ?? 0));

        return $this->ok([
            'customer' => $this->detail($customer),
            'stats' => [
                'total_orders' => (int) ($aggregate->total_orders ?? 0),
                'completed_orders' => (int) ($aggregate->completed_orders ?? 0),
                'pending_orders' => (int) ($aggregate->pending_orders ?? 0),
                'total_spent' => (float) ($aggregate->total_spent ?? 0),
                'spend_basis' => 'completed orders',
            ],
            'recent_orders' => $recentOrders,
            'transactions' => $transactions,
            'activity' => $this->activity($customer),
        ]);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        // Payloads speak lowercase statuses across this API; normalise before
        // validating so an uppercase (legacy-shaped) client still works. Only
        // when the key is actually present — a partial update must not have a
        // blank status forced onto it.
        if ($request->has('status')) {
            $request->merge(['status' => strtolower(trim((string) $request->input('status')))]);
        }

        // The edit form posts every field, so empty values are refused, but a
        // partial payload (the pre-WS-19 API contract) is still accepted and
        // leaves the omitted fields alone. The status side effect only runs
        // when a status is actually supplied.
        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:190'],
            'last_name' => ['sometimes', 'required', 'string', 'max:190'],
            // Emails are unique per business in the schema (2026_09_01
            // migration); the base endpoint still used a global unique rule.
            'email' => [
                'sometimes', 'required', 'email', 'max:190',
                Rule::unique('customers', 'email')
                    ->where(fn ($q) => $q->where('business_id', $customer->business_id))
                    ->ignore($customer->id),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'location' => ['sometimes', 'nullable', 'string', 'max:190'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'suspended', 'deleted'])],
        ]);

        if (isset($data['status'])) {
            $data['status'] = strtoupper($data['status']);
        }

        $user = $this->user($request);

        DB::transaction(function () use ($request, $customer, $data, $user) {
            $before = $this->snapshot($customer);

            $customer->fill($data);

            // Legacy side effect kept: an explicit Active verifies the email,
            // any other explicit status clears it. A partial update that omits
            // status leaves the verification untouched. The edit form does not
            // email the customer — the dedicated suspend/activate actions are
            // the notification surface.
            $status = $data['status'] ?? null;

            if ($status === Customer::STATUS_ACTIVE) {
                $customer->hasVerifiedEmail() ? $customer->save() : $customer->markEmailAsVerified();
            } elseif ($status !== null) {
                $customer->email_verified_at = null;
                $customer->save();
            } else {
                $customer->save();
            }

            $after = $this->snapshot($customer);

            if ($before !== $after) {
                ActivityLog::create([
                    'user_id' => $user->id,
                    'business_id' => $customer->business_id,
                    'action' => 'customer_updated',
                    'subject_type' => Customer::class,
                    'subject_id' => $customer->id,
                    'description' => 'Customer details updated ('.implode(', ', array_keys(array_diff_assoc($after, $before))).')',
                    'old_values' => $before,
                    'new_values' => $after,
                    'ip_address' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ]);
            }
        });

        return $this->ok(['customer' => $this->detail($customer->fresh())], 'Customer updated.');
    }

    public function suspend(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($customer->status === Customer::STATUS_SUSPENDED) {
            return $this->error('This customer is already suspended.', 422);
        }

        $user = $this->user($request);

        DB::transaction(function () use ($request, $customer, $data, $user) {
            $oldStatus = $customer->status;
            $oldVerifiedAt = $customer->email_verified_at;

            $customer->update([
                'status' => Customer::STATUS_SUSPENDED,
                'email_verified_at' => null,
            ]);

            // The reason is required and stored, so the audit trail can answer
            // "why was this customer suspended?" — the legacy API validated it
            // and threw it away, and legacy business writes hid the actor in
            // metadata.user_id instead of using the column.
            ActivityLog::create([
                'user_id' => $user->id,
                'business_id' => $customer->business_id,
                'action' => 'customer_suspended',
                'subject_type' => Customer::class,
                'subject_id' => $customer->id,
                'description' => "Suspended customer: {$customer->full_name}. Reason: {$data['reason']}",
                'old_values' => ['status' => $oldStatus, 'email_verified_at' => $oldVerifiedAt],
                'new_values' => [
                    'status' => Customer::STATUS_SUSPENDED,
                    'email_verified_at' => null,
                    'reason' => $data['reason'],
                ],
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            Log::info('api.management.customer_suspended', [
                'customer_id' => $customer->id,
                'account_id' => $customer->account_id,
                'user_id' => $user->id,
                'reason' => $data['reason'],
            ]);
        });

        return $this->ok(['customer' => $this->detail($customer->fresh())], 'Customer suspended.');
    }

    public function activate(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        if ($customer->status === Customer::STATUS_ACTIVE) {
            return $this->error('This customer is already active.', 422);
        }

        $user = $this->user($request);

        DB::transaction(function () use ($request, $customer, $user) {
            $oldVerifiedAt = $customer->email_verified_at;
            $oldStatus = $customer->status;

            $customer->status = Customer::STATUS_ACTIVE;

            $customer->hasVerifiedEmail() ? $customer->save() : $customer->markEmailAsVerified();

            ActivityLog::create([
                'user_id' => $user->id,
                'business_id' => $customer->business_id,
                'action' => 'customer_activated',
                'subject_type' => Customer::class,
                'subject_id' => $customer->id,
                'description' => "Activated customer: {$customer->full_name}",
                'old_values' => ['status' => $oldStatus, 'email_verified_at' => $oldVerifiedAt],
                'new_values' => ['status' => Customer::STATUS_ACTIVE, 'email_verified_at' => $customer->email_verified_at],
                'ip_address' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);

            Log::info('api.management.customer_activated', [
                'customer_id' => $customer->id,
                'account_id' => $customer->account_id,
                'user_id' => $user->id,
            ]);
        });

        return $this->ok(['customer' => $this->detail($customer->fresh())], 'Customer activated.');
    }

    /**
     * Business-wide for owners and their non-restricted staff; assigned stores
     * only for restricted staff, who could otherwise enumerate every customer
     * of the business (the legacy privacy gap this workstream closes).
     *
     * @param  Collection<int, int>  $storeIds
     */
    private function scopedQuery(User $user, $storeIds): Builder
    {
        return Customer::query()
            ->where('business_id', $user->business_id)
            ->when($user->isRestrictedStaff(), fn ($q) => $q->whereHas(
                'orders',
                fn ($orders) => $orders->whereIn('store_id', $storeIds)
            ));
    }

    /**
     * @param  Collection<int, int>  $storeIds
     */
    private function scopeOrders(Builder $query, User $user, $storeIds): Builder
    {
        return $user->isRestrictedStaff() ? $query->whereIn('store_id', $storeIds) : $query;
    }

    private function ordersQuery(Customer $customer, User $user): HasMany
    {
        $query = $customer->orders();

        if ($user->isRestrictedStaff()) {
            $query->whereIn('store_id', $this->userStoreIds($user));
        }

        return $query;
    }

    /**
     * @return Collection<int, int>
     */
    private function userStoreIds(User $user)
    {
        return $user->accessibleStoreIds();
    }

    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.$term.'%';

        $query->where(function ($inner) use ($like) {
            $inner->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('account_id', 'like', $like)
                // Legacy matched a single term against the composed name; the
                // base API dropped it, breaking "John Smith" searches.
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like]);
        });
    }

    private function applyCountryFilter(Builder $query, string $country): void
    {
        $query->where(fn ($inner) => $inner
            ->where('country', $country)
            ->orWhereHas('deliveryAddresses.deliveryRoute', fn ($route) => $route->where('country', $country)));
    }

    /**
     * @param  Collection<int, int>  $storeIds
     * @return array<string, int|string>
     */
    private function stats(User $user, $storeIds): array
    {
        $counts = $this->scopedQuery($user, $storeIds)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $active = (int) ($counts[Customer::STATUS_ACTIVE] ?? 0);
        $suspended = (int) ($counts[Customer::STATUS_SUSPENDED] ?? 0);

        $orders = Order::query()
            ->where('business_id', $user->business_id)
            ->when($user->isRestrictedStaff(), fn ($q) => $q->whereIn('store_id', $storeIds));

        return [
            'total' => (int) $counts->sum(),
            'active' => $active,
            'suspended' => $suspended,
            'total_orders' => (clone $orders)->count(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function activity(Customer $customer): array
    {
        return ActivityLog::query()
            ->where('subject_type', Customer::class)
            ->where('subject_id', $customer->id)
            ->with('user:id,name')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'user' => $log->user?->name,
                'old_values' => $log->old_values,
                'new_values' => $log->new_values,
                'created_at' => $log->created_at?->toISOString(),
                'created_at_human' => $log->created_at?->diffForHumans(),
            ])->values()->all();
    }

    /**
     * Scalar view of the fields the edit form owns, so the audit entry
     * compares like with like (a Carbon and its database string are not the
     * same value to a strict comparison).
     *
     * @return array<string, mixed>
     */
    private function snapshot(Customer $customer): array
    {
        return [
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'location' => $customer->location,
            'status' => $customer->status,
            'email_verified_at' => $customer->email_verified_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'account_id' => $customer->account_id,
            'name' => $customer->full_name,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'location' => $customer->location,
            'status' => strtolower($customer->status),
            'email_verified' => $customer->hasVerifiedEmail(),
            'orders_count' => isset($customer->orders_count) ? (int) $customer->orders_count : null,
            'created_at' => $customer->created_at?->toISOString(),
            'updated_at' => $customer->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Customer $customer): array
    {
        return [
            ...$this->summary($customer),
            'last_login' => $customer->last_login?->toISOString(),
            // Read from the customers-table columns, per the audit's
            // correction: the legacy controllers loaded a deliveryAddresses
            // relation nothing rendered. `apartment`/`zip_code` were dropped
            // from this table by the 2025_11_06 restructure and have not
            // come back, so they are not emitted.
            'address' => [
                'street_address' => $customer->street_address,
                'city' => $customer->city,
                'state' => $customer->state,
                'country' => $customer->country,
            ],
        ];
    }

    private function authorizeCustomer(Request $request, Customer $customer): void
    {
        $user = $this->user($request);

        if ((int) $customer->business_id !== (int) $user->business_id) {
            abort(403, 'You do not have access to this customer.');
        }

        if ($user->isRestrictedStaff()
            && ! $customer->orders()->whereIn('store_id', $this->accessibleStoreIds($request))->exists()) {
            abort(403, 'You do not have access to this customer.');
        }
    }

    private function authorizeStoreId(Request $request, int $storeId): void
    {
        if (! $this->user($request)->accessibleStores()->whereKey($storeId)->exists()) {
            abort(403, 'You do not have access to this store.');
        }
    }
}
