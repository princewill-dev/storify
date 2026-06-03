<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminVendorCreated;
use App\Models\User;
use App\Models\Setting;
use Illuminate\Http\Request;
use App\Http\Requests\Admin\VendorRequest;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\VendorSuspended;
use App\Mail\VendorReactivated;
use App\Models\KycApplication;

class VendorController extends Controller
{
    private function adminRecipients(): array
    {
        $emails = User::where('role', 'superadmin')->pluck('email')->filter()->all();
        if (empty($emails) && config('mail.from.address')) {
            $emails = [config('mail.from.address')];
        }
        return $emails;
    }

    public function index(Request $request)
    {
        Log::info('vendors_viewed', ['user_id' => auth()->id()]);
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');

        $usersQuery = User::query()
            ->with(['stores' => function($q){
                $q->select(['id','store_id','name','user_id'])->limit(3);
            }, 'kycApplication'])
            ->withCount('stores');
        if (in_array(strtolower((string)$status), ['active','suspended','deleted'], true)) {
            $usersQuery->where('status', strtolower($status));
        } else {
            // Default: hide deleted vendors unless explicitly filtered
            $usersQuery->where('status', '!=', 'deleted');
        }
        if ($q !== '') {
            $usersQuery->where(function($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                  ->orWhere('email', 'like', "%$q%")
                  ->orWhere('phone', 'like', "%$q%");
            });
        }
        if ($from || $to) {
            $start = $from ? date('Y-m-d 00:00:00', strtotime($from)) : null;
            $end = $to ? date('Y-m-d 23:59:59', strtotime($to)) : null;
            if ($start && $end) {
                $usersQuery->whereBetween('created_at', [$start, $end]);
            } elseif ($start) {
                $usersQuery->where('created_at', '>=', $start);
            } elseif ($end) {
                $usersQuery->where('created_at', '<=', $end);
            }
        }

        $users = $usersQuery->latest()->paginate(15)->withQueryString();
        return view('admin.vendors.index', [
            'vendors' => $users,
            'status' => $status,
            'q' => $q,
            'from' => $from,
            'to' => $to,
            'kycStatusBadgeData' => KycApplication::statusBadgeData(),
            'vendorStatusBadgeData' => [
                ['label' => 'Active', 'class' => 'bg-green-100 text-green-800'],
                ['label' => 'Suspended', 'class' => 'bg-red-100 text-red-800'],
                ['label' => 'Deleted', 'class' => 'bg-gray-100 text-gray-800'],
            ],
        ]);
    }

    public function create()
    {
        Log::info('vendor_create_viewed', ['user_id' => auth()->id()]);
        $allow = (int) env('ALLOW_MS_SETUP', 0) === 1;
        $superadminEmails = \App\Models\User::where('role','superadmin')->pluck('email');
        $superadminVendorExists = User::whereIn('email', $superadminEmails)->exists();
        if ($superadminVendorExists && !$allow) {
            return redirect()->back()->with('error', 'multi-vendor crontrols is incomplete');
        }
        return view('admin.vendors.create');
    }

    public function store(VendorRequest $request)
    {
        Log::info('vendor_create_requested', ['user_id' => auth()->id(), 'ip' => $request->ip()]);
        // Guard: if superadmin vendor already exists and ALLOW_MS_SETUP != 1, refuse creation
        $allow = (int) env('ALLOW_MS_SETUP', 0) === 1;
        $superadminEmails = \App\Models\User::where('role','superadmin')->pluck('email');
        $superadminVendorExists = User::whereIn('email', $superadminEmails)->exists();
        if ($superadminVendorExists && !$allow) {
            return redirect()->route('admin.vendors.index')->with('error', 'multi-vendor crontrols is incomplete');
        }
        $data = $request->validated();
        // Normalize/auto-generate slug from name if not provided
        $data['slug'] = Str::of((string)($data['slug'] ?? $data['name'] ?? ''))
            ->trim()
            ->lower()
            ->replace(' ', '_');
        $user = User::create($data);
        Log::info('vendor_created', ['user_id' => auth()->id(), 'user_id' => $user->id]);

        try {
            $admins = $this->adminRecipients();
            if (!empty($admins)) {
                Mail::to($admins)->queue(new AdminVendorCreated($user));
            }
        } catch (\Throwable $e) {
            Log::error('vendor_created_mail_queue_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
        return redirect()->route('admin.vendors.index')->with('success', 'Vendor created');
    }

    public function edit(User $user)
    {
        Log::info('vendor_edit_viewed', ['user_id' => auth()->id(), 'user_id' => $user->id]);
        return view('admin.vendors.edit', compact('user'));
    }

    public function update(VendorRequest $request, User $user)
    {
        Log::info('vendor_update_requested', ['user_id' => auth()->id(), 'user_id' => $user->id, 'ip' => $request->ip()]);
        $data = $request->validated();
        if (empty($data['slug']) && !empty($data['name'])) {
            $data['slug'] = Str::of($data['name'])->trim()->lower()->replace(' ', '_');
        } else if (!empty($data['slug'])) {
            $data['slug'] = Str::of($data['slug'])->trim()->lower()->replace(' ', '_');
        }
        $user->update($data);
        $user->refresh(); // Refresh to get updated data
        Log::info('vendor_updated', ['user_id' => auth()->id(), 'user_id' => $user->id]);
        
        // Check if redirect parameter is set to show page
        if ($request->query('redirect') === 'show') {
            return redirect()->route('admin.vendors.show', $user->account_id)->with('success', 'Vendor updated');
        }
        
        return redirect()->route('admin.vendors.index')->with('success', 'Vendor updated');
    }

    public function destroy(User $user)
    {
        Log::info('vendor_delete_requested', ['user_id' => auth()->id(), 'user_id' => $user->id]);
        
        // Check if vendor owns the main store
        $mainStoreId = Setting::value('main_store_id');
        if ($mainStoreId && $user->stores()->where('id', $mainStoreId)->exists()) {
            Log::warning('vendor_delete_blocked_main_store_owner', ['user_id' => $user->id, 'main_store_id' => $mainStoreId]);
            return back()->with('error', 'This vendor owns the main store and cannot be deleted.');
        }

        $storeIds = $user->stores()->where('status', '!=', 'deleted')->pluck('id')->toArray();

        if (!empty($storeIds)) {
            // Check if any order associated with the vendor's stores is not completed
            $incompleteOrders = \App\Models\Order::whereIn('store_id', $storeIds)
                ->where('status', '!=', \App\Enums\OrderStatus::COMPLETED->value)
                ->exists();

            if ($incompleteOrders) {
                Log::warning('vendor_delete_rejected_incomplete_orders', [
                    'user_id' => auth()->id(),
                    'user_id' => $user->id,
                    'vendor_name' => $user->name
                ]);
                return back()->with('error', "Deletion rejected: {$user->name} has stores with incomplete orders");
            }

            // Check if any transaction associated with the vendor's stores is not completed
            $incompleteTransactions = \App\Models\Transaction::whereHas('order', function($q) use ($storeIds) {
                    $q->whereIn('store_id', $storeIds);
                })
                ->where('status', '!=', \App\Enums\TransactionStatus::CONFIRMED->value)
                ->exists();

            if ($incompleteTransactions) {
                Log::warning('vendor_delete_rejected_incomplete_transactions', [
                    'user_id' => auth()->id(),
                    'user_id' => $user->id,
                    'vendor_name' => $user->name
                ]);
                return back()->with('error', "Deletion rejected: {$user->name} has stores with incomplete transactions");
            }
        }

        // If all validations pass, mark vendor as deleted
        $user->update(['status' => 'deleted']);
        Log::info('vendor_marked_deleted', ['user_id' => auth()->id(), 'user_id' => $user->id]);
        return back()->with('success', "Vendor '{$user->name}' has been deleted successfully.");
    }

    public function show(User $user)
    {
        Log::info('vendor_show_viewed', ['user_id' => auth()->id(), 'user_id' => $user->id]);
        $user->load(['stores' => function($q){ $q->with(['ownershipType','businessType']); }]);
        return view('admin.vendors.show', compact('user'));
    }

    public function suspend(Request $request, User $user)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);
        $mainStoreId = Setting::value('main_store_id');
        if ($mainStoreId && $user->stores()->where('id', $mainStoreId)->exists()) {
            Log::warning('vendor_suspend_blocked_main_store_owner', ['user_id' => $user->id, 'main_store_id' => $mainStoreId]);
            return back()->with('error', 'This vendor owns the main store and cannot be suspended.');
        }
        Log::info('vendor_suspend_requested', ['user_id' => auth()->id(), 'user_id' => $user->id, 'reason' => $data['reason']]);
        $user->update(['status' => 'suspended']);

        try {
            if ($user->email) {
                Mail::to($user->email)->queue(new VendorSuspended($user, $data['reason']));
            }
        } catch (\Throwable $e) {
            Log::error('vendor_suspended_mail_queue_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        Log::info('vendor_suspended', ['user_id' => auth()->id(), 'user_id' => $user->id]);
        return back()->with('success', 'Vendor suspended');
    }

    public function activate(Request $request, User $user)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);
        Log::info('vendor_activate_requested', ['user_id' => auth()->id(), 'user_id' => $user->id, 'reason' => $data['reason']]);
        $user->update(['status' => 'active']);

        // Approve KYC application if it exists
        $kycApplication = $user->kycApplication;
        if ($kycApplication && $kycApplication->status !== 'approved') {
            $kycApplication->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'reviewer_notes' => 'Auto-approved during vendor activation: ' . $data['reason']
            ]);
            Log::info('vendor_kyc_auto_approved', [
                'user_id' => auth()->id(),
                'user_id' => $user->id,
                'kyc_application_id' => $kycApplication->id
            ]);
        }

        try {
            if ($user->email) {
                Mail::to($user->email)->queue(new VendorReactivated($user, $data['reason']));
            }
        } catch (\Throwable $e) {
            Log::error('vendor_reactivated_mail_queue_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        Log::info('vendor_activated', ['user_id' => auth()->id(), 'user_id' => $user->id]);
        
        $message = 'Vendor activated';
        if ($kycApplication && $kycApplication->status === 'approved') {
            $message .= ' and KYC approved';
        }
        
        return back()->with('success', $message);
    }
}
