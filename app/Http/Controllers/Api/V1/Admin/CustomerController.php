<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Mail\CustomerAccountActivatedMail;
use App\Mail\CustomerAccountSuspendedMail;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\ActivityRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * WS-9 (admin console) — platform customer console.
 *
 * The legacy `/office/customers` screens (directory, detail, edit, suspend,
 * activate) had no equivalent in the new stack at all: the only customer API
 * was the business-scoped management one, which cannot serve platform
 * oversight. This controller is the platform-wide console. It is deliberately
 * unaware of tenant scoping — a platform admin sees every business's
 * customers — and is guarded instead by `permission:admin.customers` plus the
 * platform-role check in the EnsuresPlatformAdmin trait.
 *
 * Legacy defects fixed rather than cloned:
 * - `sort_by`/`sort_order` were passed straight to `orderBy` (a SQL injection
 *   seam with arbitrary columns in the error path); the column and direction
 *   are whitelisted here.
 * - The country dropdown joined `delivery_addresses` to `delivery_routes` on
 *   every request; it is derived once and cached, and it also includes the
 *   address columns the legacy detail card actually rendered, so every option
 *   in the dropdown filters at least one row.
 * - The unused `this_month` counter is dropped, per the roadmap ("drop the
 *   unused stat or display it deliberately") — it never reached the view.
 * - Suspension/activation e-mails were sent inside the DB transaction, so a
 *   slow/newly-down mail transport held the row lock; they are queued after
 *   the commit and a mail failure never rolls the mutation back (legacy
 *   logged them non-fatally).
 * - The "already suspended"/"already active" guards refused with a warning
 *   flash and no status code; they are 422s here.
 * - Legacy `Customer::STATUS_*` is uppercase in the schema; payloads speak
 *   lowercase (matching the management API) and inputs are normalised, so an
 *   uppercase legacy-shaped client still works.
 */
class CustomerController extends ApiController
{
    use EnsuresPlatformAdmin;

    /**
     * The directory. Stats are platform-wide and deliberately independent of
     * the active filters — they are the cards above the table, not the page
     * count (the pagination meta answers that).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($request->filled('status')) {
            $request->merge(['status' => strtolower(trim((string) $request->input('status')))]);
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'deleted'])],
            'country' => ['nullable', 'string', 'max:100'],
            // Legacy handed these straight to orderBy; whitelist only.
            'sort' => ['nullable', Rule::in(['first_name', 'last_name', 'email', 'status', 'orders_count', 'created_at', 'last_login'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $customers = Customer::query()
            ->with(['business:id,name,business_code'])
            ->withCount('orders')
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $this->applySearch($q, $term))
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', strtoupper($status)))
            ->when($filters['country'] ?? null, fn (Builder $q, string $country) => $this->applyCountryFilter($q, $country))
            ->orderBy($filters['sort'] ?? 'created_at', $filters['direction'] ?? 'desc')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            ['customers' => $customers->getCollection()->map(fn (Customer $customer) => $this->summary($customer))->values()->all()],
            null,
            200,
            [...$this->paginationMeta($customers), 'stats' => $this->stats()],
        );
    }

    /**
     * The country filter's options.
     *
     * Two sources, same as the management parity screen: the address columns
     * on the customer (what the legacy address card rendered) and the
     * delivery-route country the legacy filter joined. Cached for ten minutes
     * instead of running the join per request; the customer count changes far
     * slower than the page is loaded.
     */
    public function countries(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $countries = Cache::remember('admin.customer_countries', now()->addMinutes(10), function () {
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

        return $this->ok(['countries' => $countries]);
    }

    /**
     * The customer console: the four stat tiles, the info and address cards,
     * the last ten orders and transactions, and the audit feed the legacy
     * detail page rendered.
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $orders = $customer->orders();

        // Aggregated in SQL: the money column is summed as a decimal, never
        // accumulated in PHP.
        $aggregate = (clone $orders)->selectRaw("
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders
        ")->first();

        // Legacy's spend basis: the order total only counts when a confirmed
        // transaction backs it.
        $totalSpent = (clone $orders)
            ->whereHas('transactions', fn ($query) => $query->where('status', TransactionStatus::CONFIRMED->value))
            ->sum('total');

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
                'status_label' => $transaction->status?->label(),
                'payment_method' => $transaction->paymentMethod?->name,
                'paid_at' => $transaction->paid_at?->toISOString(),
                'created_at' => $transaction->created_at?->toISOString(),
            ])->values()->all();

        // The list's withCount alias has no counterpart on the bound model, so
        // the same figure the tiles use is written into the payload here.
        $detail = $this->detail($customer);
        $detail['orders_count'] = (int) ($aggregate->total_orders ?? 0);

        return $this->ok([
            'customer' => $detail,
            'stats' => [
                'total_orders' => (int) ($aggregate->total_orders ?? 0),
                'completed_orders' => (int) ($aggregate->completed_orders ?? 0),
                'pending_orders' => (int) ($aggregate->pending_orders ?? 0),
                'total_spent' => (float) $totalSpent,
                'spend_basis' => 'orders with confirmed transactions',
            ],
            'recent_orders' => $recentOrders,
            'transactions' => $transactions,
            'activity' => $this->activity($customer),
        ]);
    }

    /**
     * Edit details and status from the list or the console.
     *
     * Legacy's status side effect is kept: an explicit Active verifies the
     * e-mail, any other explicit status clears it. A partial payload that
     * omits status leaves verification untouched (the legacy form always
     * posted the status, but nothing else in this API requires a full form).
     */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($request->has('status')) {
            $request->merge(['status' => strtolower(trim((string) $request->input('status')))]);
        }

        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:190'],
            'last_name' => ['sometimes', 'required', 'string', 'max:190'],
            // Emails are unique per business in the schema; the same address
            // may legitimately exist for customers of two different businesses.
            'email' => [
                'sometimes', 'required', 'email', 'max:190',
                Rule::unique('customers', 'email')
                    ->where(fn ($query) => $query->where('business_id', $customer->business_id))
                    ->ignore($customer->id),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'location' => ['sometimes', 'nullable', 'string', 'max:190'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'suspended', 'deleted'])],
        ]);

        if (isset($data['status'])) {
            $data['status'] = strtoupper($data['status']);
        }

        DB::transaction(function () use ($request, $customer, $data) {
            $before = $this->snapshot($customer);

            $customer->fill($data);

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
                ActivityRecorder::record(
                    action: 'customer_updated',
                    description: 'Customer details updated ('.implode(', ', array_keys(array_diff_assoc($after, $before))).')',
                    subject: $customer,
                    old: $before,
                    new: $after,
                    actor: $request->user(),
                    businessId: $customer->business_id,
                );
            }
        });

        return $this->ok(['customer' => $this->detail($customer->fresh())], 'Customer updated.');
    }

    /**
     * Suspend with a required, persisted reason and the customer e-mail the
     * legacy screen sent.
     */
    public function suspend(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($customer->status === Customer::STATUS_SUSPENDED) {
            return $this->error('This customer is already suspended.', 422);
        }

        $oldStatus = $customer->status;
        $oldVerifiedAt = $customer->email_verified_at;

        DB::transaction(function () use ($request, $customer, $data, $oldStatus, $oldVerifiedAt) {
            // Suspending also un-verifies the e-mail, legacy's enable/disable
            // semantics: a suspended account cannot sign back in on the
            // strength of a previously verified address.
            $customer->update([
                'status' => Customer::STATUS_SUSPENDED,
                'email_verified_at' => null,
            ]);

            ActivityRecorder::record(
                action: 'customer_suspended',
                description: "Suspended customer: {$customer->full_name}. Reason: {$data['reason']}",
                subject: $customer,
                old: ['status' => $oldStatus, 'email_verified_at' => $oldVerifiedAt?->toISOString()],
                new: ['status' => Customer::STATUS_SUSPENDED, 'email_verified_at' => null, 'reason' => $data['reason']],
                actor: $request->user(),
                businessId: $customer->business_id,
            );
        });

        Log::info('api.admin.customer_suspended', [
            'customer_id' => $customer->id,
            'account_id' => $customer->account_id,
            'actor_user_id' => $request->user()?->id,
        ]);

        $this->notifyCustomer(
            $customer,
            fn () => new CustomerAccountSuspendedMail($customer, $data['reason']),
            'api.admin.customer_suspension_email_failed',
        );

        return $this->ok(['customer' => $this->detail($customer->fresh())], 'Customer suspended.');
    }

    /**
     * Activate a suspended or deleted customer, verifying the e-mail if it is
     * not already, with legacy's notification.
     */
    public function activate(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($customer->status === Customer::STATUS_ACTIVE) {
            return $this->error('This customer is already active.', 422);
        }

        $oldStatus = $customer->status;
        $oldVerifiedAt = $customer->email_verified_at;

        DB::transaction(function () use ($request, $customer, $oldStatus, $oldVerifiedAt) {
            $customer->status = Customer::STATUS_ACTIVE;

            $customer->hasVerifiedEmail() ? $customer->save() : $customer->markEmailAsVerified();

            ActivityRecorder::record(
                action: 'customer_activated',
                description: "Activated customer: {$customer->full_name}",
                subject: $customer,
                old: ['status' => $oldStatus, 'email_verified_at' => $oldVerifiedAt?->toISOString()],
                new: ['status' => Customer::STATUS_ACTIVE, 'email_verified_at' => $customer->email_verified_at?->toISOString()],
                actor: $request->user(),
                businessId: $customer->business_id,
            );
        });

        Log::info('api.admin.customer_activated', [
            'customer_id' => $customer->id,
            'account_id' => $customer->account_id,
            'actor_user_id' => $request->user()?->id,
        ]);

        $this->notifyCustomer(
            $customer,
            fn () => new CustomerAccountActivatedMail($customer),
            'api.admin.customer_activation_email_failed',
        );

        return $this->ok(['customer' => $this->detail($customer->fresh())], 'Customer activated.');
    }

    /**
     * Legacy's search: name (including the composed full name), e-mail, phone
     * and account id.
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
     */
    private function applyCountryFilter(Builder $query, string $country): void
    {
        $query->where(fn ($inner) => $inner
            ->where('country', $country)
            ->orWhereHas('deliveryAddresses.deliveryRoute', fn ($route) => $route->where('country', $country)));
    }

    /**
     * Platform-wide stat cards. `total` includes suspended and deleted
     * accounts (the directory lists them); `orders` counts live orders only,
     * where legacy's raw table count also counted soft-deleted rows.
     *
     * @return array<string, int>
     */
    private function stats(): array
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
     * compares like with like.
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
            'country' => $customer->country,
            'status' => strtolower($customer->status),
            'email_verified' => $customer->hasVerifiedEmail(),
            'orders_count' => isset($customer->orders_count) ? (int) $customer->orders_count : null,
            'business' => $customer->business?->name,
            'business_code' => $customer->business?->business_code,
            'business_id' => $customer->business_id,
            'last_login' => $customer->last_login?->toISOString(),
            'created_at' => $customer->created_at?->toISOString(),
            'updated_at' => $customer->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Customer $customer): array
    {
        $customer->loadMissing('business:id,name,business_code');

        // The legacy detail card read the customer-table address columns; the
        // default delivery address (what the storefront actually ships to) is
        // surfaced alongside them as a nullable extra.
        $deliveryAddress = $customer->deliveryAddresses()
            ->orderByDesc('is_default')
            ->latest()
            ->first();

        return [
            ...$this->summary($customer),
            'address' => [
                'street_address' => $customer->street_address,
                'city' => $customer->city,
                'state' => $customer->state,
                'country' => $customer->country,
            ],
            'default_delivery_address' => $deliveryAddress ? [
                'id' => $deliveryAddress->id,
                'recipient_name' => $deliveryAddress->recipient_name,
                'recipient_phone' => $deliveryAddress->recipient_phone,
                'street_address' => $deliveryAddress->street_address,
                'apartment' => $deliveryAddress->apartment,
                'city' => $deliveryAddress->city,
                'state' => $deliveryAddress->state,
                'country' => $deliveryAddress->country,
                'zip_code' => $deliveryAddress->zip_code,
                'is_default' => (bool) $deliveryAddress->is_default,
                'full_address' => $deliveryAddress->full_address,
            ] : null,
        ];
    }

    /**
     * A failed notification must never undo the mutation — it is queued after
     * the commit and the failure is logged, as legacy did.
     */
    private function notifyCustomer(Customer $customer, callable $mailable, string $failureLog): void
    {
        if (empty($customer->email)) {
            return;
        }

        try {
            Mail::to($customer->email)->queue($mailable());
        } catch (\Throwable $e) {
            Log::error($failureLog, [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The admin console is a platform surface. Audience + `admin.customers`
     * alone are not enough: every business's in-business "Super Admin" role is
     * seeded with the full permission bundle, which contains the admin.* names,
     * so a business-scoped account holding a leaked admin-audience token would
     * otherwise read and mutate every tenant's customers (the same hole WS-1
     * and WS-4 documented).
     */
}
