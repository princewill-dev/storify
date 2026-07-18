<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Models\ActivityLog;
use App\Mail\CustomerAccountSuspendedMail;
use App\Mail\CustomerAccountActivatedMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    /**
     * Display a listing of customers.
     */
    public function index(Request $request)
    {
        $query = Customer::with(['deliveryAddresses'])->withCount('orders');

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('account_id', 'like', "%{$search}%");
            });
        }

        // Filter by customer status
        if ($request->filled('status')) {
            $status = strtoupper($request->status);
            $query->where('status', $status);
        }

        // Filter by country
        if ($request->filled('country')) {
            $country = $request->country;
            $query->whereHas('deliveryAddresses.deliveryRoute', function($q) use ($country) {
                $q->where('country', $country);
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $customers = $query->paginate(20);

        // Statistics
        $stats = [
            'total' => Customer::count(),
            'active' => Customer::where('status', Customer::STATUS_ACTIVE)->count(),
            'suspended' => Customer::where('status', Customer::STATUS_SUSPENDED)->count(),
            'total_orders' => DB::table('orders')->count(),
            'this_month' => Customer::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
        ];

        // Get unique countries from delivery routes linked to delivery addresses
        $countries = DB::table('delivery_addresses as da')
            ->join('delivery_routes as dr', 'da.delivery_route_id', '=', 'dr.id')
            ->select('dr.country')
            ->whereNotNull('dr.country')
            ->where('dr.country', '!=', '')
            ->distinct()
            ->orderBy('dr.country')
            ->pluck('dr.country');

        return view('admin.customers.index', [
            'customers' => $customers,
            'stats' => $stats,
            'countries' => $countries,
            'statusBadgeData' => Customer::statusBadgeData(),
        ]);
    }

    /**
     * Display the specified customer.
     */
    public function show(Customer $customer)
    {
        $customer->load([
            'deliveryAddresses',
            'orders' => function($query) {
                $query->with(['store', 'items'])
                      ->latest()
                      ->limit(10);
            }
        ]);

        // Get customer statistics
        $stats = [
            'total_orders' => $customer->orders()->count(),
            'completed_orders' => $customer->orders()->where('status', 'completed')->count(),
            'total_spent' => $customer->orders()->whereHas('transactions', fn($q) => $q->where('status', 'confirmed'))->sum('total'),
            'pending_orders' => $customer->orders()->where('status', 'pending')->count(),
        ];

        // Get recent transactions
        $transactions = \App\Models\Transaction::whereHas('order', function($q) use ($customer) {
                $q->where('customer_id', $customer->id);
            })
            ->with(['order', 'paymentMethod'])
            ->latest()
            ->limit(10)
            ->get();

        // Get activity logs
        $activityLogs = ActivityLog::where('subject_type', Customer::class)
            ->where('subject_id', $customer->id)
            ->with('user')
            ->latest()
            ->limit(20)
            ->get();

        return view('admin.customers.show', compact('customer', 'stats', 'transactions', 'activityLogs'));
    }

    /**
     * Show the form for editing the specified customer.
     */
    public function edit(Customer $customer)
    {
        $statuses = [
            Customer::STATUS_ACTIVE => 'Active',
            Customer::STATUS_SUSPENDED => 'Suspended',
            Customer::STATUS_DELETED => 'Deleted',
        ];

        return view('admin.customers.edit', compact('customer', 'statuses'));
    }

    /**
     * Update the specified customer in storage.
     */
    public function update(Request $request, Customer $customer)
    {
        $statusKeys = [Customer::STATUS_ACTIVE, Customer::STATUS_SUSPENDED, Customer::STATUS_DELETED];

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

        return redirect()->route('admin.customers.show', $customer)
            ->with('success', 'Customer updated successfully.');
    }

    /**
     * Suspend a customer account.
     */
    public function suspend(Request $request, Customer $customer)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        if ($customer->status === Customer::STATUS_SUSPENDED) {
            return back()->with('warning', 'Customer is already suspended.');
        }

        try {
            DB::beginTransaction();

            // Mark email as unverified to suspend account
            $oldVerifiedAt = $customer->email_verified_at;
            $customer->email_verified_at = null;
            $customer->status = Customer::STATUS_SUSPENDED;
            $customer->save();

            // Log activity
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'customer_suspended',
                'subject_type' => Customer::class,
                'subject_id' => $customer->id,
                'description' => "Suspended customer: {$customer->full_name}. Reason: {$request->reason}",
                'old_values' => json_encode(['email_verified_at' => $oldVerifiedAt]),
                'new_values' => json_encode(['email_verified_at' => null, 'reason' => $request->reason]),
                'ip_address' => $request->ip(),
            ]);

            Log::info('customer_suspended', [
                'customer_id' => $customer->id,
                'account_id' => $customer->account_id,
                'admin_id' => Auth::id(),
                'reason' => $request->reason,
            ]);

            // Send email notification
            try {
                Mail::to($customer->email)->send(new CustomerAccountSuspendedMail($customer, $request->reason));
                
                Log::info('customer_suspension_email_sent', [
                    'customer_id' => $customer->id,
                    'email' => $customer->email,
                ]);
            } catch (\Exception $e) {
                Log::error('customer_suspension_email_failed', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::commit();

            return back()->with('success', 'Customer account suspended successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('customer_suspension_failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to suspend customer account.');
        }
    }

    /**
     * Activate a customer account.
     */
    public function activate(Request $request, Customer $customer)
    {
        try {
            DB::beginTransaction();

            if ($customer->status === Customer::STATUS_ACTIVE) {
                return back()->with('warning', 'Customer is already active.');
            }

            $oldVerifiedAt = $customer->email_verified_at;
            $customer->status = Customer::STATUS_ACTIVE;
            if (!$customer->hasVerifiedEmail()) {
                $customer->markEmailAsVerified();
            } else {
                $customer->save();
            }

            // Log activity
            ActivityLog::create([
                'user_id' => Auth::id(),
                'action' => 'customer_activated',
                'subject_type' => Customer::class,
                'subject_id' => $customer->id,
                'description' => "Activated customer: {$customer->full_name}",
                'old_values' => json_encode(['email_verified_at' => $oldVerifiedAt]),
                'new_values' => json_encode(['email_verified_at' => $customer->email_verified_at]),
                'ip_address' => $request->ip(),
            ]);

            Log::info('customer_activated', [
                'customer_id' => $customer->id,
                'account_id' => $customer->account_id,
                'admin_id' => Auth::id(),
            ]);

            // Send email notification
            try {
                Mail::to($customer->email)->send(new CustomerAccountActivatedMail($customer));
                
                Log::info('customer_activation_email_sent', [
                    'customer_id' => $customer->id,
                    'email' => $customer->email,
                ]);
            } catch (\Exception $e) {
                Log::error('customer_activation_email_failed', [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::commit();

            return back()->with('success', 'Customer account activated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('customer_activation_failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to activate customer account.');
        }
    }
}
