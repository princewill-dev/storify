<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Store;
use App\Models\User;
use App\Models\OwnershipType;
use App\Models\BusinessType;
use App\Models\Product;
use App\Models\Category;
use App\Models\Pack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\AdminStoreCreated;
use App\Mail\VendorStoreCreated;
use App\Mail\VendorStoreSuspended;
use App\Mail\VendorStoreReactivated;
use App\Models\Setting;

class StoreController extends Controller
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
        Log::info('stores_viewed', ['user_id' => auth()->id()]);
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');

        $storesQuery = Store::query()->with(['vendor', 'business', 'ownershipType', 'businessType'])
            ->where('status', '!=', 'deleted');
        if (in_array(strtolower((string)$status), ['active','inactive','suspended','deleted'], true)) {
            $storesQuery->where('status', strtolower($status));
        }
        if ($q !== '') {
            $storesQuery->where(function($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                  ->orWhere('store_id', 'like', "%$q%")
                  ->orWhereHas('vendor', function($v) use ($q) { $v->where('name', 'like', "%$q%"); });
            });
        }
        if ($from || $to) {
            $start = $from ? date('Y-m-d 00:00:00', strtotime($from)) : null;
            $end = $to ? date('Y-m-d 23:59:59', strtotime($to)) : null;
            if ($start && $end) {
                $storesQuery->whereBetween('created_at', [$start, $end]);
            } elseif ($start) {
                $storesQuery->where('created_at', '>=', $start);
            } elseif ($end) {
                $storesQuery->where('created_at', '<=', $end);
            }
        }

        $stores = $storesQuery->latest()->paginate(15)->withQueryString();
        $mainStoreId = Setting::value('main_store_id');
        // For create/edit modals
        $businesses = Business::with('owner')->orderBy('name')->get();
        $ownershipTypes = OwnershipType::orderBy('name')->get();
        $businessTypes = BusinessType::orderBy('name')->get();
        return view('admin.stores.index', compact('stores','mainStoreId','status','q','from','to','businesses','ownershipTypes','businessTypes'))
            ->with('storeStatusBadgeData', Store::statusBadgeData());
    }

    public function show(Store $store)
    {
        Log::info('store_show_viewed', ['user_id' => auth()->id(), 'store_id' => $store->id]);
        $store->load(['vendor', 'business', 'ownershipType', 'businessType']);
        $productCount = Product::where('store_id', $store->id)->count();
        $recentProducts = Product::where('store_id', $store->id)->latest()->take(10)->get();
        $categories = Category::where('store_id', $store->id)->orderBy('name')->get();
        $packs = Pack::where('store_id', $store->id)->latest()->take(10)->get();
        // For inline modals on the show page
        $businesses = Business::with('owner')->orderBy('name')->get();
        $ownershipTypes = OwnershipType::orderBy('name')->get();
        $businessTypes = BusinessType::orderBy('name')->get();
        return view('admin.stores.show', compact('store','productCount','recentProducts','categories','packs','businesses','ownershipTypes','businessTypes'));
    }

    public function create()
    {
        Log::info('store_create_viewed', ['user_id' => auth()->id()]);
        $mainStoreId = Setting::value('main_store_id');
        $allow = (int) env('ALLOW_MS_SETUP', 0) === 1;
        if ($mainStoreId && !$allow) {
            return redirect()->back()->with('error', 'multi-vendor crontrols is incomplete');
        }
        $lockVendor = false;
        $lockedVendor = null;
        if (!$allow) {
            $superadmin = User::where('role', 'superadmin')->orderBy('id')->first();
            if ($superadmin) {
                $lockedVendor = User::where('email', $superadmin->email)->first();
                if ($lockedVendor) { $lockVendor = true; }
            }
        }
        $businesses = Business::with('owner')->orderBy('name')->get();
        $ownershipTypes = OwnershipType::orderBy('name')->get();
        $businessTypes = BusinessType::orderBy('name')->get();
        return view('admin.stores.create', compact('businesses','ownershipTypes','businessTypes','lockVendor','lockedVendor'));
    }

    public function store(Request $request)
    {
        Log::info('store_create_requested', ['user_id' => auth()->id(), 'ip' => $request->ip()]);
        // Guard: if main store already exists and ALLOW_MS_SETUP != 1, refuse creation
        $allow = (int) env('ALLOW_MS_SETUP', 0) === 1;
        $mainStoreId = Setting::value('main_store_id');
        if ($mainStoreId && !$allow) {
            return redirect()->route('admin.stores.index')->with('error', 'multi-vendor crontrols is incomplete');
        }
        $data = $request->validate([
            'business_id' => 'required|exists:businesses,id',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'support_email' => 'nullable|email|max:255',
            'support_phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'instagram_url' => 'nullable|string|max:255',
            'facebook_url' => 'nullable|string|max:255',
            'twitter_url' => 'nullable|string|max:255',
            'tiktok_url' => 'nullable|string|max:255',
            'ownership_type_id' => 'nullable|exists:ownership_types,id',
            'business_type_id' => 'nullable|exists:business_types,id',
            'status' => 'required|string|max:50',
        ]);
        // Normalize slug if provided; otherwise generated in model
        if (!empty($data['slug']) || !empty($data['name'])) {
            $base = strtolower(str_replace(' ', '_', $data['slug'] ?? $data['name']));
            $data['slug'] = $base;
        }
        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('stores/logos', 'public');
        }
        unset($data['logo']);
        // Resolve user_id from business
        $business = Business::find($data['business_id']);
        $data['user_id'] = $business?->user_id;
        unset($data['business_id']);
        // If multi-vendor setup not allowed, always attach superadmin's vendor
        if (!$allow) {
            $superadmin = User::where('role', 'superadmin')->orderBy('id')->first();
            if ($superadmin) {
                $saVendor = User::where('email', $superadmin->email)->first();
                if ($saVendor) { $data['user_id'] = $saVendor->id; }
            }
        }
        $store = Store::create($data);
        Log::info('store_created', ['user_id' => auth()->id(), 'store_id' => $store->id, 'public_id' => $store->store_id]);

        // If this store is created by a superadmin and no main store is configured yet, set it as main/homepage store
        try {
            $actorRole = auth()->user()?->role;
            if ($actorRole === 'superadmin') {
                $settings = Setting::query()->first();
                if (!$settings) { $settings = new Setting(); }
                if (empty($settings->main_store_id)) {
                    $settings->main_store_id = $store->id;
                    $settings->save();
                    Log::info('main_store_configured', ['store_id' => $store->id, 'by_user_id' => auth()->id()]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('main_store_auto_config_failed', ['store_id' => $store->id, 'error' => $e->getMessage()]);
        }

        // Queue emails per workflow (background queues)
        try {
            $admins = $this->adminRecipients();
            if (!empty($admins)) {
                Mail::to($admins)->queue(new AdminStoreCreated($store));
            }
        } catch (\Throwable $e) {
            Log::error('store_created_admin_mail_queue_failed', ['store_id' => $store->id, 'error' => $e->getMessage()]);
        }
        try {
            $userEmail = User::find($store->user_id)?->email;
            if ($userEmail) {
                Mail::to($userEmail)->queue(new VendorStoreCreated($store));
            }
        } catch (\Throwable $e) {
            Log::error('store_created_vendor_mail_queue_failed', ['store_id' => $store->id, 'error' => $e->getMessage()]);
        }
        return redirect()->route('admin.stores.index')->with('success', 'Store created');
    }

    public function edit(Store $store)
    {
        Log::info('store_edit_viewed', ['user_id' => auth()->id(), 'store_id' => $store->id]);
        $businesses = Business::with('owner')->orderBy('name')->get();
        $ownershipTypes = OwnershipType::orderBy('name')->get();
        $businessTypes = BusinessType::orderBy('name')->get();
        return view('admin.stores.edit', compact('store','businesses','ownershipTypes','businessTypes'));
    }

    public function update(Request $request, Store $store)
    {
        Log::info('store_update_requested', ['user_id' => auth()->id(), 'store_id' => $store->id, 'ip' => $request->ip()]);
        $data = $request->validate([
            'business_id' => 'required|exists:businesses,id',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
            'support_email' => 'nullable|email|max:255',
            'support_phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
            'instagram_url' => 'nullable|string|max:255',
            'facebook_url' => 'nullable|string|max:255',
            'twitter_url' => 'nullable|string|max:255',
            'tiktok_url' => 'nullable|string|max:255',
            'ownership_type_id' => 'nullable|exists:ownership_types,id',
            'business_type_id' => 'nullable|exists:business_types,id',
            'status' => 'required|string|max:50',
        ]);
        // Normalize and ensure unique slug on update
        if (empty($data['slug']) && !empty($data['name'])) {
            $data['slug'] = strtolower(str_replace(' ', '_', $data['name']));
        } else if (!empty($data['slug'])) {
            $data['slug'] = strtolower(str_replace(' ', '_', $data['slug']));
        }
        if (!empty($data['slug'])) {
            $base = $data['slug'];
            $slug = $base;
            $tries = 0;
            while (Store::where('slug', $slug)->where('id', '!=', $store->id)->exists()) {
                $suffix = '-' . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
                $slug = $base . $suffix;
                if (++$tries > 10) { break; }
            }
            $data['slug'] = $slug;
        }
        if ($request->hasFile('logo')) {
            if ($store->logo_path) {
                try {
                    Storage::disk('public')->delete($store->logo_path);
                } catch (\Throwable $e) {
                    Log::warning('store_logo_delete_failed', ['store_id' => $store->id, 'error' => $e->getMessage()]);
                }
            }
            $data['logo_path'] = $request->file('logo')->store('stores/logos', 'public');
        }
        unset($data['logo']);
        // Resolve user_id from business
        $business = Business::find($data['business_id']);
        $data['user_id'] = $business?->user_id;
        unset($data['business_id']);
        // Prevent inactivating/suspending the main homepage store via edit form
        $blockedStatusChange = false;
        if (isset($data['status']) && in_array(strtolower($data['status']), ['inactive','suspended'], true)) {
            $mainStoreId = Setting::value('main_store_id');
            if ($mainStoreId && (int)$store->id === (int)$mainStoreId) {
                unset($data['status']);
                $blockedStatusChange = true;
                Log::warning('store_status_change_blocked_main_store', [
                    'user_id' => auth()->id(),
                    'store_id' => $store->id,
                    'attempted_status' => $request->input('status'),
                    'main_store_id' => $mainStoreId,
                ]);
            }
        }
        $oldStatus = $store->status;
        $store->update($data);
        Log::info('store_updated', ['user_id' => auth()->id(), 'store_id' => $store->id]);

        // If status changed to a suspended-like state, notify vendor
        if ($oldStatus !== $store->status && in_array(strtolower($store->status), ['inactive','suspended'], true)) {
            try {
                $userEmail = User::find($store->user_id)?->email;
                if ($userEmail) {
                    Mail::to($userEmail)->queue(new VendorStoreSuspended($store));
                }
            } catch (\Throwable $e) {
                Log::error('store_suspension_vendor_mail_queue_failed', ['store_id' => $store->id, 'error' => $e->getMessage()]);
            }
        }
        // Determine redirect target: prefer explicit redirect_to when present
        $redirectTarget = $request->input('redirect_to');
        if ($redirectTarget) {
            $redirect = redirect($redirectTarget)->with('success', 'Store updated');
        } else {
            $redirect = redirect()->route('admin.stores.index')->with('success', 'Store updated');
        }
        if ($blockedStatusChange) {
            $redirect->with('warning', 'This store is configured as the homepage store and cannot be set to inactive. Other details were updated.');
        }
        return $redirect;
    }

    public function destroy(Store $store)
    {
        Log::info('store_delete_requested', ['user_id' => auth()->id(), 'store_id' => $store->id]);

        // Check if this is the main store
        $mainStoreId = Setting::value('main_store_id');
        if ($mainStoreId && (int)$store->id === (int)$mainStoreId) {
            Log::warning('store_delete_blocked_main_store', ['user_id' => auth()->id(), 'store_id' => $store->id, 'main_store_id' => $mainStoreId]);
            return back()->with('error', 'This is the main store and cannot be deleted.');
        }

        // Check if any order associated with the store is not completed
        $incompleteOrders = \App\Models\Order::where('store_id', $store->id)
            ->where('status', '!=', \App\Enums\OrderStatus::COMPLETED->value)
            ->exists();

        if ($incompleteOrders) {
            Log::warning('store_delete_rejected_incomplete_orders', [
                'user_id' => auth()->id(),
                'store_id' => $store->id,
                'store_name' => $store->name
            ]);
            return back()->with('error', "Deletion rejected: {$store->name} has an incomplete order");
        }

        // Check if any transaction associated with the store is not completed
        $incompleteTransactions = \App\Models\Transaction::whereHas('order', function($q) use ($store) {
                $q->where('store_id', $store->id);
            })
            ->where('status', '!=', \App\Enums\TransactionStatus::CONFIRMED->value)
            ->exists();

        if ($incompleteTransactions) {
            Log::warning('store_delete_rejected_incomplete_transactions', [
                'user_id' => auth()->id(),
                'store_id' => $store->id,
                'store_name' => $store->name
            ]);
            return back()->with('error', "Deletion rejected: {$store->name} has an incomplete transaction");
        }

        // If all orders and transactions are completed, mark store as deleted
        $store->update(['status' => 'deleted']);
        
        Log::info('store_deleted', ['user_id' => auth()->id(), 'store_id' => $store->id]);
        return back()->with('success', "Store '{$store->name}' has been deleted successfully.");
    }

    public function suspend(Request $request, Store $store)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);
        $mainStoreId = Setting::value('main_store_id');
        if ($mainStoreId && (int)$store->id === (int)$mainStoreId) {
            Log::warning('store_suspend_blocked_main_store', ['user_id' => auth()->id(), 'store_id' => $store->id, 'main_store_id' => $mainStoreId]);
            return back()->with('error', 'This is the main store and cannot be suspended.');
        }
        Log::info('store_suspend_requested', ['user_id' => auth()->id(), 'store_id' => $store->id, 'reason' => $data['reason']]);
        $store->update(['status' => 'suspended']);

        try {
            $userEmail = User::find($store->user_id)?->email;
            if ($userEmail) {
                Mail::to($userEmail)->queue(new VendorStoreSuspended($store, $data['reason']));
            }
        } catch (\Throwable $e) {
            Log::error('store_suspended_vendor_mail_queue_failed', ['store_id' => $store->id, 'error' => $e->getMessage()]);
        }

        Log::info('store_suspended', ['user_id' => auth()->id(), 'store_id' => $store->id]);
        return back()->with('success', 'Store suspended');
    }

    public function activate(Request $request, Store $store)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);
        Log::info('store_activate_requested', ['user_id' => auth()->id(), 'store_id' => $store->id, 'reason' => $data['reason']]);
        $store->update(['status' => 'active']);

        try {
            $userEmail = User::find($store->user_id)?->email;
            if ($userEmail) {
                Mail::to($userEmail)->queue(new VendorStoreReactivated($store, $data['reason']));
            }
        } catch (\Throwable $e) {
            Log::error('store_reactivated_vendor_mail_queue_failed', ['store_id' => $store->id, 'error' => $e->getMessage()]);
        }

        Log::info('store_activated', ['user_id' => auth()->id(), 'store_id' => $store->id]);
        return back()->with('success', 'Store activated');
    }
}
