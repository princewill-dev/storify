<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BusinessRequest;
use App\Mail\AdminBusinessCreated;
use App\Mail\BusinessReactivated;
use App\Mail\BusinessSuspended;
use App\Models\Business;
use App\Models\KycApplication;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class BusinessController extends Controller
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
        Log::info('businesses_viewed', ['user_id' => auth()->id()]);
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');

        $businessQuery = Business::query()
            ->with(['owner', 'stores', 'warehouses', 'activeSubscription'])
            ->withCount(['stores', 'warehouses', 'users']);

        if (in_array(strtolower((string) $status), ['active', 'suspended', 'deleted'], true)) {
            $businessQuery->where('status', strtolower($status));
        } else {
            $businessQuery->where('status', '!=', 'deleted');
        }
        if ($q !== '') {
            $businessQuery->where(function ($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                    ->orWhere('business_code', 'like', "%$q%")
                    ->orWhereHas('owner', fn ($o) => $o->where('name', 'like', "%$q%")->orWhere('email', 'like', "%$q%"));
            });
        }
        if ($from || $to) {
            $start = $from ? date('Y-m-d 00:00:00', strtotime($from)) : null;
            $end = $to ? date('Y-m-d 23:59:59', strtotime($to)) : null;
            if ($start && $end) {
                $businessQuery->whereBetween('created_at', [$start, $end]);
            } elseif ($start) {
                $businessQuery->where('created_at', '>=', $start);
            } elseif ($end) {
                $businessQuery->where('created_at', '<=', $end);
            }
        }

        $businesses = $businessQuery->latest()->paginate(15)->withQueryString();

        return view('admin.businesses.index', [
            'businesses' => $businesses,
            'status' => $status,
            'q' => $q,
            'from' => $from,
            'to' => $to,
            'kycStatusBadgeData' => KycApplication::statusBadgeData(),
            'businessStatusBadgeData' => [
                ['label' => 'Active', 'class' => 'bg-green-100 text-green-800'],
                ['label' => 'Suspended', 'class' => 'bg-red-100 text-red-800'],
                ['label' => 'Deleted', 'class' => 'bg-gray-100 text-gray-800'],
            ],
        ]);
    }

    public function create()
    {
        Log::info('business_create_viewed', ['user_id' => auth()->id()]);
        $allow = (int) env('ALLOW_MS_SETUP', 0) === 1;
        $superadminEmails = User::where('role', 'superadmin')->pluck('email');
        $superadminBusinessExists = User::whereIn('email', $superadminEmails)->exists();
        if ($superadminBusinessExists && ! $allow) {
            return redirect()->back()->with('error', 'multi-business crontrols is incomplete');
        }

        return view('admin.businesses.create');
    }

    public function store(BusinessRequest $request)
    {
        Log::info('business_create_requested', ['user_id' => auth()->id(), 'ip' => $request->ip()]);
        // Guard: if superadmin business already exists and ALLOW_MS_SETUP != 1, refuse creation
        $allow = (int) env('ALLOW_MS_SETUP', 0) === 1;
        $superadminEmails = User::where('role', 'superadmin')->pluck('email');
        $superadminBusinessExists = User::whereIn('email', $superadminEmails)->exists();
        if ($superadminBusinessExists && ! $allow) {
            return redirect()->route('admin.businesses.index')->with('error', 'multi-business crontrols is incomplete');
        }
        $data = $request->validated();
        // Normalize/auto-generate slug from name if not provided
        $data['slug'] = Str::of((string) ($data['slug'] ?? $data['name'] ?? ''))
            ->trim()
            ->lower()
            ->replace(' ', '_');
        $user = User::create($data);
        Log::info('business_created', ['actor_user_id' => auth()->id(), 'business_owner_id' => $user->id]);

        try {
            $admins = $this->adminRecipients();
            if (! empty($admins)) {
                Mail::to($admins)->queue(new AdminBusinessCreated($user));
            }
        } catch (\Throwable $e) {
            Log::error('business_created_mail_queue_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        return redirect()->route('admin.businesses.index')->with('success', 'Business created');
    }

    public function edit(User $user)
    {
        Log::info('business_edit_viewed', ['user_id' => auth()->id(), 'user_id' => $user->id]);

        return view('admin.businesses.edit', compact('user'));
    }

    public function update(BusinessRequest $request, User $user)
    {
        Log::info('business_update_requested', ['user_id' => auth()->id(), 'user_id' => $user->id, 'ip' => $request->ip()]);
        $data = $request->validated();
        if (empty($data['slug']) && ! empty($data['name'])) {
            $data['slug'] = Str::of($data['name'])->trim()->lower()->replace(' ', '_');
        } elseif (! empty($data['slug'])) {
            $data['slug'] = Str::of($data['slug'])->trim()->lower()->replace(' ', '_');
        }
        $user->update($data);
        $user->refresh(); // Refresh to get updated data
        Log::info('business_updated', ['user_id' => auth()->id(), 'user_id' => $user->id]);

        // Check if redirect parameter is set to show page
        if ($request->query('redirect') === 'show') {
            return redirect()->route('admin.businesses.show', $user->account_id)->with('success', 'Business updated');
        }

        return redirect()->route('admin.businesses.index')->with('success', 'Business updated');
    }

    public function destroy(User $user)
    {
        Log::info('business_delete_requested', ['user_id' => auth()->id(), 'user_id' => $user->id]);

        // Check if business owns the main store
        $mainStoreId = Setting::value('main_store_id');
        if ($mainStoreId && $user->stores()->where('id', $mainStoreId)->exists()) {
            Log::warning('business_delete_blocked_main_store_owner', ['user_id' => $user->id, 'main_store_id' => $mainStoreId]);

            return back()->with('error', 'This business owns the main store and cannot be deleted.');
        }

        $storeIds = $user->stores()->where('status', '!=', 'deleted')->pluck('id')->toArray();

        if (! empty($storeIds)) {
            // Check if any order associated with the business's stores is not completed
            $incompleteOrders = Order::whereIn('store_id', $storeIds)
                ->where('status', '!=', OrderStatus::COMPLETED->value)
                ->exists();

            if ($incompleteOrders) {
                Log::warning('business_delete_rejected_incomplete_orders', [
                    'user_id' => auth()->id(),
                    'user_id' => $user->id,
                    'business_name' => $user->name,
                ]);

                return back()->with('error', "Deletion rejected: {$user->name} has stores with incomplete orders");
            }

            // Check if any transaction associated with the business's stores is not completed
            $incompleteTransactions = Transaction::whereHas('order', function ($q) use ($storeIds) {
                $q->whereIn('store_id', $storeIds);
            })
                ->where('status', '!=', TransactionStatus::CONFIRMED->value)
                ->exists();

            if ($incompleteTransactions) {
                Log::warning('business_delete_rejected_incomplete_transactions', [
                    'user_id' => auth()->id(),
                    'user_id' => $user->id,
                    'business_name' => $user->name,
                ]);

                return back()->with('error', "Deletion rejected: {$user->name} has stores with incomplete transactions");
            }
        }

        // If all validations pass, mark business as deleted
        $user->update(['status' => 'deleted']);
        Log::info('business_marked_deleted', ['user_id' => auth()->id(), 'user_id' => $user->id]);

        return back()->with('success', "Business '{$user->name}' has been deleted successfully.");
    }

    public function show(User $user)
    {
        Log::info('business_show_viewed', ['user_id' => auth()->id(), 'business_user_id' => $user->id]);
        $business = Business::where('user_id', $user->id)->first();
        if ($business) {
            $business->load([
                'owner',
                'stores' => fn ($q) => $q->with(['ownershipType', 'businessType']),
                'warehouses' => fn ($q) => $q->withCount('stockLocations'),
                'users' => fn ($q) => $q->with('roles'),
                'activeSubscription.subscriptionPlan',
                'kycApplications' => fn ($q) => $q->latest(),
            ]);
            $business->loadCount(['stores', 'warehouses', 'users']);
        }

        return view('admin.businesses.show', compact('user', 'business'));
    }

    public function suspend(Request $request, User $user)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);
        $mainStoreId = Setting::value('main_store_id');
        if ($mainStoreId && $user->stores()->where('id', $mainStoreId)->exists()) {
            Log::warning('business_suspend_blocked_main_store_owner', ['user_id' => $user->id, 'main_store_id' => $mainStoreId]);

            return back()->with('error', 'This business owns the main store and cannot be suspended.');
        }
        Log::info('business_suspend_requested', ['user_id' => auth()->id(), 'user_id' => $user->id, 'reason' => $data['reason']]);
        $user->update(['status' => 'suspended']);

        try {
            if ($user->email) {
                Mail::to($user->email)->queue(new BusinessSuspended($user, $data['reason']));
            }
        } catch (\Throwable $e) {
            Log::error('business_suspended_mail_queue_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        Log::info('business_suspended', ['user_id' => auth()->id(), 'user_id' => $user->id]);

        return back()->with('success', 'Business suspended');
    }

    public function activate(Request $request, User $user)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);
        Log::info('business_activate_requested', ['user_id' => auth()->id(), 'user_id' => $user->id, 'reason' => $data['reason']]);
        $user->update(['status' => 'active']);

        // Approve KYC application if it exists
        $kycApplication = $user->kycApplication;
        if ($kycApplication && $kycApplication->status !== 'approved') {
            $kycApplication->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
                'reviewer_notes' => 'Auto-approved during business activation: '.$data['reason'],
            ]);
            Log::info('business_kyc_auto_approved', [
                'user_id' => auth()->id(),
                'user_id' => $user->id,
                'kyc_application_id' => $kycApplication->id,
            ]);
        }

        try {
            if ($user->email) {
                Mail::to($user->email)->queue(new BusinessReactivated($user, $data['reason']));
            }
        } catch (\Throwable $e) {
            Log::error('business_reactivated_mail_queue_failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        Log::info('business_activated', ['user_id' => auth()->id(), 'user_id' => $user->id]);

        $message = 'Business activated';
        if ($kycApplication && $kycApplication->status === 'approved') {
            $message .= ' and KYC approved';
        }

        return back()->with('success', $message);
    }
}
