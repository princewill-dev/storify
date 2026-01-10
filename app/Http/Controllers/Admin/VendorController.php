<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\AdminVendorCreated;
use App\Models\Vendor;
use App\Models\Setting;
use Illuminate\Http\Request;
use App\Http\Requests\Admin\VendorRequest;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\VendorSuspended;
use App\Mail\VendorReactivated;
use App\Models\User;
use App\Models\VendorKycApplication;

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

        $vendorsQuery = Vendor::query()
            ->with(['stores' => function($q){
                $q->select(['id','store_id','name','vendor_id'])->limit(3);
            }, 'kycApplication'])
            ->withCount('stores');
        if (in_array(strtolower((string)$status), ['active','suspended','deleted'], true)) {
            $vendorsQuery->where('status', strtolower($status));
        } else {
            // Default: hide deleted vendors unless explicitly filtered
            $vendorsQuery->where('status', '!=', 'deleted');
        }
        if ($q !== '') {
            $vendorsQuery->where(function($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                  ->orWhere('email', 'like', "%$q%")
                  ->orWhere('phone', 'like', "%$q%");
            });
        }
        if ($from || $to) {
            $start = $from ? date('Y-m-d 00:00:00', strtotime($from)) : null;
            $end = $to ? date('Y-m-d 23:59:59', strtotime($to)) : null;
            if ($start && $end) {
                $vendorsQuery->whereBetween('created_at', [$start, $end]);
            } elseif ($start) {
                $vendorsQuery->where('created_at', '>=', $start);
            } elseif ($end) {
                $vendorsQuery->where('created_at', '<=', $end);
            }
        }

        $vendors = $vendorsQuery->latest()->paginate(15)->withQueryString();
        return view('admin.vendors.index', [
            'vendors' => $vendors,
            'status' => $status,
            'q' => $q,
            'from' => $from,
            'to' => $to,
            'kycStatusBadgeData' => VendorKycApplication::statusBadgeData(),
            'vendorStatusBadgeData' => Vendor::statusBadgeData(),
        ]);
    }

    public function create()
    {
        Log::info('vendor_create_viewed', ['user_id' => auth()->id()]);
        $allow = (int) env('ALLOW_MS_SETUP', 0) === 1;
        $superadminEmails = \App\Models\User::where('role','superadmin')->pluck('email');
        $superadminVendorExists = Vendor::whereIn('email', $superadminEmails)->exists();
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
        $superadminVendorExists = Vendor::whereIn('email', $superadminEmails)->exists();
        if ($superadminVendorExists && !$allow) {
            return redirect()->route('admin.vendors.index')->with('error', 'multi-vendor crontrols is incomplete');
        }
        $data = $request->validated();
        // Normalize/auto-generate slug from name if not provided
        $data['slug'] = Str::of((string)($data['slug'] ?? $data['name'] ?? ''))
            ->trim()
            ->lower()
            ->replace(' ', '_');
        $vendor = Vendor::create($data);
        Log::info('vendor_created', ['user_id' => auth()->id(), 'vendor_id' => $vendor->id]);

        try {
            $admins = $this->adminRecipients();
            if (!empty($admins)) {
                Mail::to($admins)->queue(new AdminVendorCreated($vendor));
            }
        } catch (\Throwable $e) {
            Log::error('vendor_created_mail_queue_failed', ['vendor_id' => $vendor->id, 'error' => $e->getMessage()]);
        }
        return redirect()->route('admin.vendors.index')->with('success', 'Vendor created');
    }

    public function edit(Vendor $vendor)
    {
        Log::info('vendor_edit_viewed', ['user_id' => auth()->id(), 'vendor_id' => $vendor->id]);
        return view('admin.vendors.edit', compact('vendor'));
    }

    public function update(VendorRequest $request, Vendor $vendor)
    {
        Log::info('vendor_update_requested', ['user_id' => auth()->id(), 'vendor_id' => $vendor->id, 'ip' => $request->ip()]);
        $data = $request->validated();
        if (empty($data['slug']) && !empty($data['name'])) {
            $data['slug'] = Str::of($data['name'])->trim()->lower()->replace(' ', '_');
        } else if (!empty($data['slug'])) {
            $data['slug'] = Str::of($data['slug'])->trim()->lower()->replace(' ', '_');
        }
        $vendor->update($data);
        $vendor->refresh(); // Refresh to get updated data
        Log::info('vendor_updated', ['user_id' => auth()->id(), 'vendor_id' => $vendor->id]);
        
        // Check if redirect parameter is set to show page
        if ($request->query('redirect') === 'show') {
            return redirect()->route('admin.vendors.show', $vendor->account_id)->with('success', 'Vendor updated');
        }
        
        return redirect()->route('admin.vendors.index')->with('success', 'Vendor updated');
    }

    public function destroy(Vendor $vendor)
    {
        Log::info('vendor_delete_requested', ['user_id' => auth()->id(), 'vendor_id' => $vendor->id]);
        
        // Check if vendor owns the main store
        $mainStoreId = Setting::value('main_store_id');
        if ($mainStoreId && $vendor->stores()->where('id', $mainStoreId)->exists()) {
            Log::warning('vendor_delete_blocked_main_store_owner', ['vendor_id' => $vendor->id, 'main_store_id' => $mainStoreId]);
            return back()->with('error', 'This vendor owns the main store and cannot be deleted.');
        }

        // Get all store IDs belonging to this vendor
        $storeIds = $vendor->stores()->pluck('id')->toArray();

        if (!empty($storeIds)) {
            // Check if any order associated with the vendor's stores is not completed
            $incompleteOrders = \App\Models\Order::whereIn('store_id', $storeIds)
                ->where('status', '!=', \App\Enums\OrderStatus::COMPLETED->value)
                ->exists();

            if ($incompleteOrders) {
                Log::warning('vendor_delete_rejected_incomplete_orders', [
                    'user_id' => auth()->id(),
                    'vendor_id' => $vendor->id,
                    'vendor_name' => $vendor->name
                ]);
                return back()->with('error', "Deletion rejected: {$vendor->name} has stores with incomplete orders");
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
                    'vendor_id' => $vendor->id,
                    'vendor_name' => $vendor->name
                ]);
                return back()->with('error', "Deletion rejected: {$vendor->name} has stores with incomplete transactions");
            }
        }

        // If all validations pass, mark vendor as deleted
        $vendor->update(['status' => 'deleted']);
        Log::info('vendor_marked_deleted', ['user_id' => auth()->id(), 'vendor_id' => $vendor->id]);
        return back()->with('success', "Vendor '{$vendor->name}' has been deleted successfully.");
    }

    public function show(Vendor $vendor)
    {
        Log::info('vendor_show_viewed', ['user_id' => auth()->id(), 'vendor_id' => $vendor->id]);
        $vendor->load(['stores' => function($q){ $q->with(['ownershipType','businessType']); }]);
        return view('admin.vendors.show', compact('vendor'));
    }

    public function suspend(Request $request, Vendor $vendor)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);
        $mainStoreId = Setting::value('main_store_id');
        if ($mainStoreId && $vendor->stores()->where('id', $mainStoreId)->exists()) {
            Log::warning('vendor_suspend_blocked_main_store_owner', ['vendor_id' => $vendor->id, 'main_store_id' => $mainStoreId]);
            return back()->with('error', 'This vendor owns the main store and cannot be suspended.');
        }
        Log::info('vendor_suspend_requested', ['user_id' => auth()->id(), 'vendor_id' => $vendor->id, 'reason' => $data['reason']]);
        $vendor->update(['status' => 'suspended']);

        try {
            if ($vendor->email) {
                Mail::to($vendor->email)->queue(new VendorSuspended($vendor, $data['reason']));
            }
        } catch (\Throwable $e) {
            Log::error('vendor_suspended_mail_queue_failed', ['vendor_id' => $vendor->id, 'error' => $e->getMessage()]);
        }

        Log::info('vendor_suspended', ['user_id' => auth()->id(), 'vendor_id' => $vendor->id]);
        return back()->with('success', 'Vendor suspended');
    }

    public function activate(Request $request, Vendor $vendor)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);
        Log::info('vendor_activate_requested', ['user_id' => auth()->id(), 'vendor_id' => $vendor->id, 'reason' => $data['reason']]);
        $vendor->update(['status' => 'active']);

        // Approve KYC application if it exists
        $kycApplication = $vendor->kycApplication;
        if ($kycApplication && $kycApplication->status !== 'approved') {
            $kycApplication->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'reviewer_notes' => 'Auto-approved during vendor activation: ' . $data['reason']
            ]);
            Log::info('vendor_kyc_auto_approved', [
                'user_id' => auth()->id(),
                'vendor_id' => $vendor->id,
                'kyc_application_id' => $kycApplication->id
            ]);
        }

        try {
            if ($vendor->email) {
                Mail::to($vendor->email)->queue(new VendorReactivated($vendor, $data['reason']));
            }
        } catch (\Throwable $e) {
            Log::error('vendor_reactivated_mail_queue_failed', ['vendor_id' => $vendor->id, 'error' => $e->getMessage()]);
        }

        Log::info('vendor_activated', ['user_id' => auth()->id(), 'vendor_id' => $vendor->id]);
        
        $message = 'Vendor activated';
        if ($kycApplication && $kycApplication->status === 'approved') {
            $message .= ' and KYC approved';
        }
        
        return back()->with('success', $message);
    }
}
