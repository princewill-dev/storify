<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{


    private function customerBelongsToUser(Customer $customer, User $user): bool
    {
        return $customer->business_id === $user->business_id;
    }

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        $query = Customer::query()
            ->with('deliveryAddresses')
            ->withCount(['orders as orders_count' => fn($q) => $q->where('user_id', $user->id)]);
        $this->forBusiness($query, $user);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('account_id', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', strtoupper(trim($request->status)));
        }

        if ($request->filled('country')) {
            $query->whereHas('deliveryAddresses.deliveryRoute', function ($q) use ($request) {
                $q->where('country', $request->country);
            });
        }

        if ($request->filled('store_id')) {
            $query->whereHas('orders', fn($q) => $q->where('store_id', $request->store_id));
        }

        $customers = $query->latest('created_at')->paginate(20)->withQueryString();

        $baseCustomers = Customer::query()
            ->where('business_id', $user->business_id)
            ->whereHas('orders', fn($q) => $q->where('user_id', $user->id));
        $this->forBusiness($baseCustomers, $user);

        $stats = [
            'total' => (clone $baseCustomers)->count(),
            'active' => (clone $baseCustomers)->where('status', Customer::STATUS_ACTIVE)->count(),
            'suspended' => (clone $baseCustomers)->where('status', Customer::STATUS_SUSPENDED)->count(),
            'total_orders' => Order::where('user_id', $user->id)->where('business_id', $user->business_id)->count(),
        ];

        $countries = DB::table('delivery_addresses as da')
            ->join('orders', 'da.id', '=', 'orders.delivery_address_id')
            ->join('delivery_routes as dr', 'da.delivery_route_id', '=', 'dr.id')
            ->where('orders.user_id', $user->id)
            ->whereNotNull('dr.country')
            ->where('dr.country', '!=', '')
            ->distinct()
            ->orderBy('dr.country')
            ->pluck('dr.country');

        $stores = $user->stores()->where('status', '!=', 'deleted')->orderBy('name')->get();

        $activeFilters = $request->only(['search', 'status', 'country', 'store_id']);

        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Customers']];
        return view('management.customers.index', compact('customers', 'stats', 'countries', 'stores', 'activeFilters', 'breadcrumbs'));
    }

    public function show(Request $request, Customer $customer): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->customerBelongsToUser($customer, $user)) {
            return redirect()->route('management.auth.login');
        }

        $customer->load([
            'deliveryAddresses',
            'orders' => function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->with(['store', 'items'])
                    ->latest()
                    ->limit(10);
            },
        ]);

        $stats = $customer->orders()->where('user_id', $user->id)
            ->selectRaw("
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
                COALESCE(SUM(total), 0) as total_spent,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders
            ")->first();

        $stats = [
            'total_orders' => (int) ($stats->total_orders ?? 0),
            'completed_orders' => (int) ($stats->completed_orders ?? 0),
            'total_spent' => (float) ($stats->total_spent ?? 0),
            'pending_orders' => (int) ($stats->pending_orders ?? 0),
        ];

        $transactions = DB::table('transactions')
            ->join('orders', 'transactions.order_id', '=', 'orders.id')
            ->join('payment_methods', 'transactions.payment_method_id', '=', 'payment_methods.id')
            ->where('orders.customer_id', $customer->id)
            ->where('orders.user_id', $user->id)
            ->select(
                'transactions.*',
                'orders.order_number',
                'payment_methods.name as payment_method_name'
            )
            ->orderBy('transactions.created_at', 'desc')
            ->limit(10)
            ->get();

        $activityLogs = ActivityLog::where('subject_type', Customer::class)
            ->where('subject_id', $customer->id)
            ->with('user')
            ->latest()
            ->limit(20)
            ->get();

        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Customers', 'url' => route('management.customers.index')], ['label' => $customer->full_name]];
        return view('management.customers.show', compact('customer', 'stats', 'transactions', 'activityLogs', 'breadcrumbs'));
    }

    public function edit(Request $request, Customer $customer): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->customerBelongsToUser($customer, $user)) {
            return redirect()->route('management.auth.login');
        }

        $statuses = [
            Customer::STATUS_ACTIVE => 'Active',
            Customer::STATUS_SUSPENDED => 'Suspended',
            Customer::STATUS_DELETED => 'Deleted',
        ];

        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Customers', 'url' => route('management.customers.index')], ['label' => $customer->full_name, 'url' => route('management.customers.show', $customer)], ['label' => 'Edit']];
        return view('management.customers.edit', compact('customer', 'statuses', 'breadcrumbs'));
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->customerBelongsToUser($customer, $user)) {
            return redirect()->route('management.auth.login');
        }

        $statusKeys = [
            Customer::STATUS_ACTIVE,
            Customer::STATUS_SUSPENDED,
            Customer::STATUS_DELETED,
        ];

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:190'],
            'last_name' => ['required', 'string', 'max:190'],
            'email' => ['required', 'email', 'max:190', Rule::unique('customers', 'email')->ignore($customer->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:190'],
            'status' => ['required', Rule::in($statusKeys)],
        ]);

        $customer->fill($data);

        if ($data['status'] === Customer::STATUS_ACTIVE) {
            if (!$customer->hasVerifiedEmail()) {
                $customer->markEmailAsVerified();
            } else {
                $customer->save();
            }
        } else {
            $customer->email_verified_at = null;
            $customer->save();
        }

        return redirect()->route('management.customers.show', ['user' => $user, 'customer' => $customer])
            ->with('success', 'Customer updated successfully.');
    }

    public function suspend(Request $request, Customer $customer): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->customerBelongsToUser($customer, $user)) {
            return redirect()->route('management.auth.login');
        }

        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        if ($customer->status === Customer::STATUS_SUSPENDED) {
            return back()->with('warning', 'Customer is already suspended.');
        }

        try {
            DB::beginTransaction();

            $oldVerifiedAt = $customer->email_verified_at;
            $customer->status = Customer::STATUS_SUSPENDED;
            $customer->email_verified_at = null;
            $customer->save();

            ActivityLog::create([
                'user_id' => null,
                'action' => 'customer_suspended',
                'subject_type' => Customer::class,
                'subject_id' => $customer->id,
                'description' => "Vendor suspended customer: {$customer->full_name}. Reason: {$request->reason}",
                'old_values' => json_encode(['email_verified_at' => $oldVerifiedAt]),
                'new_values' => json_encode(['email_verified_at' => null, 'reason' => $request->reason]),
                'ip_address' => $request->ip(),
                'metadata' => ['user_id' => $user->id],
            ]);

            Log::info('vendor_customer_suspended', [
                'customer_id' => $customer->id,
                'account_id' => $customer->account_id,
                'user_id' => $user->id,
                'reason' => $request->reason,
            ]);

            DB::commit();

            return back()->with('success', 'Customer account suspended successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('vendor_customer_suspension_failed', [
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to suspend customer account.');
        }
    }

    public function activate(Request $request, Customer $customer): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->customerBelongsToUser($customer, $user)) {
            return redirect()->route('management.auth.login');
        }

        if ($customer->status === Customer::STATUS_ACTIVE) {
            return back()->with('warning', 'Customer is already active.');
        }

        try {
            DB::beginTransaction();
            $oldVerifiedAt = $customer->email_verified_at;

            $customer->status = Customer::STATUS_ACTIVE;
            if (!$customer->hasVerifiedEmail()) {
                $customer->markEmailAsVerified();
            } else {
                $customer->save();
            }

            ActivityLog::create([
                'user_id' => null,
                'action' => 'customer_activated',
                'subject_type' => Customer::class,
                'subject_id' => $customer->id,
                'description' => "Vendor activated customer: {$customer->full_name}",
                'old_values' => json_encode(['email_verified_at' => $oldVerifiedAt]),
                'new_values' => json_encode(['email_verified_at' => $customer->email_verified_at]),
                'ip_address' => $request->ip(),
                'metadata' => ['user_id' => $user->id],
            ]);

            Log::info('vendor_customer_activated', [
                'customer_id' => $customer->id,
                'account_id' => $customer->account_id,
                'user_id' => $user->id,
            ]);

            DB::commit();

            return back()->with('success', 'Customer account activated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('vendor_customer_activation_failed', [
                'customer_id' => $customer->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to activate customer account.');
        }
    }
}
