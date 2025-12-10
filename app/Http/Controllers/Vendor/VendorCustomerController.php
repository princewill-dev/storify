<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class VendorCustomerController extends Controller
{
    private function resolveVendor(Request $request, Vendor $routeVendor): ?Vendor
    {
        $vendor = $request->user('vendor');

        if (!$vendor || $vendor->id !== $routeVendor->id) {
            return null;
        }

        return $vendor;
    }

    private function customerBelongsToVendor(Customer $customer, Vendor $vendor): bool
    {
        return $customer->orders()->where('vendor_id', $vendor->id)->exists();
    }

    public function index(Request $request, Vendor $routeVendor): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        $query = Customer::query()
            ->whereHas('orders', fn($q) => $q->where('vendor_id', $vendor->id))
            ->with('deliveryAddresses')
            ->withCount(['orders as orders_count' => fn($q) => $q->where('vendor_id', $vendor->id)]);

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

        $customers = $query->latest('created_at')->paginate(20)->withQueryString();

        $baseCustomers = Customer::query()
            ->whereHas('orders', fn($q) => $q->where('vendor_id', $vendor->id));

        $stats = [
            'total' => (clone $baseCustomers)->count(),
            'active' => (clone $baseCustomers)->where('status', Customer::STATUS_ACTIVE)->count(),
            'suspended' => (clone $baseCustomers)->where('status', Customer::STATUS_SUSPENDED)->count(),
            'total_orders' => Order::where('vendor_id', $vendor->id)->count(),
        ];

        $countries = DB::table('delivery_addresses as da')
            ->join('orders', 'da.id', '=', 'orders.delivery_address_id')
            ->join('delivery_routes as dr', 'da.delivery_route_id', '=', 'dr.id')
            ->where('orders.vendor_id', $vendor->id)
            ->whereNotNull('dr.country')
            ->where('dr.country', '!=', '')
            ->distinct()
            ->orderBy('dr.country')
            ->pluck('dr.country');

        return view('vendors.customers.index', compact('customers', 'stats', 'countries', 'vendor'));
    }

    public function show(Request $request, Vendor $routeVendor, Customer $customer): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->customerBelongsToVendor($customer, $vendor)) {
            return redirect()->route('vendor.auth.login');
        }

        $customer->load([
            'deliveryAddresses',
            'orders' => function ($query) use ($vendor) {
                $query->where('vendor_id', $vendor->id)
                    ->with(['store', 'items'])
                    ->latest()
                    ->limit(10);
            },
        ]);

        $stats = [
            'total_orders' => $customer->orders()->where('vendor_id', $vendor->id)->count(),
            'completed_orders' => $customer->orders()->where('vendor_id', $vendor->id)->where('status', 'completed')->count(),
            'total_spent' => $customer->orders()->where('vendor_id', $vendor->id)->where('payment_status', 'paid')->sum('total'),
            'pending_orders' => $customer->orders()->where('vendor_id', $vendor->id)->where('status', 'pending')->count(),
        ];

        $transactions = DB::table('transactions')
            ->join('orders', 'transactions.order_id', '=', 'orders.id')
            ->join('payment_methods', 'transactions.payment_method_id', '=', 'payment_methods.id')
            ->where('orders.customer_id', $customer->id)
            ->where('orders.vendor_id', $vendor->id)
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

        return view('vendors.customers.show', compact('customer', 'stats', 'transactions', 'activityLogs', 'vendor'));
    }

    public function edit(Request $request, Vendor $routeVendor, Customer $customer): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->customerBelongsToVendor($customer, $vendor)) {
            return redirect()->route('vendor.auth.login');
        }

        $statuses = [
            Customer::STATUS_ACTIVE => 'Active',
            Customer::STATUS_SUSPENDED => 'Suspended',
            Customer::STATUS_DELETED => 'Deleted',
        ];

        return view('vendors.customers.edit', compact('customer', 'statuses', 'vendor'));
    }

    public function update(Request $request, Vendor $routeVendor, Customer $customer): RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->customerBelongsToVendor($customer, $vendor)) {
            return redirect()->route('vendor.auth.login');
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

        return redirect()->route('vendor.customers.show', ['vendor' => $vendor, 'customer' => $customer])
            ->with('success', 'Customer updated successfully.');
    }

    public function suspend(Request $request, Vendor $routeVendor, Customer $customer): RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->customerBelongsToVendor($customer, $vendor)) {
            return redirect()->route('vendor.auth.login');
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
                'metadata' => ['vendor_id' => $vendor->id],
            ]);

            Log::info('vendor_customer_suspended', [
                'customer_id' => $customer->id,
                'account_id' => $customer->account_id,
                'vendor_id' => $vendor->id,
                'reason' => $request->reason,
            ]);

            DB::commit();

            return back()->with('success', 'Customer account suspended successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('vendor_customer_suspension_failed', [
                'customer_id' => $customer->id,
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to suspend customer account.');
        }
    }

    public function activate(Request $request, Vendor $routeVendor, Customer $customer): RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->customerBelongsToVendor($customer, $vendor)) {
            return redirect()->route('vendor.auth.login');
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
                'metadata' => ['vendor_id' => $vendor->id],
            ]);

            Log::info('vendor_customer_activated', [
                'customer_id' => $customer->id,
                'account_id' => $customer->account_id,
                'vendor_id' => $vendor->id,
            ]);

            DB::commit();

            return back()->with('success', 'Customer account activated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('vendor_customer_activation_failed', [
                'customer_id' => $customer->id,
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to activate customer account.');
        }
    }
}
