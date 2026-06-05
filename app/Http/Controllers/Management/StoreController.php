<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\BusinessType;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Order;
use App\Models\OwnershipType;
use App\Models\Pack;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use App\Models\KycApplication;
use App\Http\Requests\Management\CreateStoreRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Illuminate\Http\JsonResponse;
use App\Mail\VendorStoreSuspended;
use App\Mail\VendorStoreReactivated;

class StoreController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        

        if (!$user->is_verified) {
            return redirect()->route('management.auth.verify-otp')
                ->with('warning', 'Please verify your email to continue.');
        }


        $ownershipTypes = OwnershipType::orderBy('name')->get(['id', 'name']);
        $businessTypes = BusinessType::orderBy('name')->get(['id', 'name']);
        $currencies = Currency::orderBy('name')->get();
        $activeStaff = User::where('business_id', $user->business_id)->where('role', 'staff')->where('status', 'active')->with('roles')->get(['id', 'name']);
        $userBanks = StoreBank::where(function ($q) use ($user) {
            $q->whereIn('store_id', $user->stores()->pluck('id'))->orWhereNull('store_id');
        })->with('store')->get();

        $prefill = session('pending_store_defaults', []);
        $defaults = [
            'name' => $prefill['name'] ?? $user->name,
            'support_email' => $prefill['support_email'] ?? $user->email,
            'support_phone' => $prefill['support_phone'] ?? $user->phone,
            'address' => $prefill['address'] ?? ($user->location ?? ''),
        ];

        Log::info('store.create_viewed', [
            'user_id' => $user->id,
        ]);

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Stores', 'url' => route('management.stores.index')],
            ['label' => 'Create'],
        ];

        return view('management.stores.create', compact('user', 'ownershipTypes', 'businessTypes', 'defaults', 'activeStaff', 'userBanks', 'currencies', 'breadcrumbs'));
    }

    public function checkSlugAvailability(Request $request): JsonResponse
    {
        $name = $request->input('name', '');
        $slug = Str::slug($name);

        if (empty($slug)) {
            return response()->json(['available' => false, 'slug' => '', 'url' => '']);
        }

        $original = $slug;
        $counter = 1;
        while (Store::where('slug', $slug)->exists()) {
            $slug = $original . '-' . $counter++;
            if ($counter > 20) break;
        }

        $domain = config('app.main_domain', parse_url(config('app.url'), PHP_URL_HOST));

        return response()->json([
            'available' => $slug === $original,
            'slug' => $slug,
            'url' => $slug . '.' . $domain,
            'original' => $original,
        ]);
    }

    public function store(CreateStoreRequest $request): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        

        if (!$user->is_verified) {
            return redirect()->route('management.auth.verify-otp')
                ->with('warning', 'Please verify your email to continue.');
        }


        $pendingDefaults = session('pending_store_defaults', []);
        $hadStores = $user->stores()->exists();

        $data = $request->validated();

        $data['support_email'] = $data['support_email'] ?? $pendingDefaults['support_email'] ?? $user->email;
        $data['support_phone'] = $data['support_phone'] ?? $pendingDefaults['support_phone'] ?? $user->phone;
        $data['address'] = $data['address'] ?? $pendingDefaults['address'] ?? $user->location;
        $data['user_id'] = $user->id;
        $data['business_id'] = $user->business_id;
        $data['status'] = $user->business?->hasActiveSubscription() ? 'active' : 'inactive';

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('stores/logos', 'public');
        }
        unset($data['logo']);

        try {
            DB::beginTransaction();
            $store = Store::create($data);
            
            if ($request->filled('bank_id')) {
                $bank = StoreBank::find($request->bank_id);
                $isOwned = $bank && (
                    $bank->store_id === null ||
                    in_array($bank->store_id, $user->stores()->pluck('id')->toArray())
                );
                if ($isOwned) {
                    $bank->update(['store_id' => $store->id, 'is_primary' => true]);
                }
            }

            if ($request->filled('staff_ids') && is_array($request->staff_ids) && !empty(array_filter($request->staff_ids))) {
                $store->assignedStaff()->sync(array_filter($request->staff_ids));
            }

            DB::commit();

            Log::info('store.created', [
                'user_id' => $user->id,
                'store_id' => $store->id,
                'store_public_id' => $store->store_id,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            if (!empty($data['logo_path'] ?? null)) {
                try {
                    Storage::disk('public')->delete($data['logo_path']);
                } catch (\Throwable $cleanup) {
                    Log::warning('store.logo_cleanup_failed', [
                        'user_id' => $user->id,
                        'error' => $cleanup->getMessage(),
                    ]);
                }
            }

            Log::error('store.create_failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->withInput()->with('error', 'We could not create your store right now. Please try again.');
        }

        if (!$hadStores) {
            session()->forget('pending_store_defaults');
        }

        return redirect()->route('management.stores.show', ['store' => $store->store_id])
            ->with('success', 'Store created successfully!');
    }

    public function success(Request $request, Store $store): View|RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        

        if (!$this->canAccessStore($user, $store)) {
            Log::warning('store.success_unauthorized', [
                'user_id' => $user->id,
                'store_id' => $store->id,
            ]);
            return redirect()->route('management.stores.create')->with('error', 'You do not have access to that store.');
        }

        $storeUrl = null;
        if (!empty($store->slug) && app('router')->has('home.store.products.index')) {
            try {
                $storeUrl = route('home.store.products.index', ['store_subdomain' => $store->slug]);
            } catch (\Throwable $e) {
                Log::warning('store.success_url_generation_failed', [
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Stores', 'url' => route('management.stores.index')], ['label' => $store->name, 'url' => route('management.stores.show', $store)], ['label' => 'Setup Complete']];

        return view('management.stores.success', [
            'store' => $store,
            'storeUrl' => $storeUrl,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    public function index(Request $request): View|RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        $status = strtolower((string) $request->query('status', ''));
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');

        $storesQuery = $user->stores()->with(['ownershipType', 'businessType'])->withCount(['products', 'categories']);

        if (in_array($status, ['active', 'inactive', 'suspended', 'deleted'], true)) {
            $storesQuery->where('status', $status);
        } else {
            $storesQuery->where('status', '!=', 'deleted');
        }

        if ($q !== '') {
            $storesQuery->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('store_id', 'like', "%{$q}%");
            });
        }

        if ($from) {
            $storesQuery->whereDate('created_at', '>=', $from);
        }

        if ($to) {
            $storesQuery->whereDate('created_at', '<=', $to);
        }

        $stores = $storesQuery->latest()->paginate(10)->withQueryString();
        $ownershipTypes = OwnershipType::orderBy('name')->get(['id', 'name']);
        $businessTypes = BusinessType::orderBy('name')->get(['id', 'name']);
        $canCreate = true;

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Stores'],
        ];

        return view('management.stores.index', [
            'stores' => $stores,
            'status' => $request->query('status'),
            'q' => $q,
            'from' => $from,
            'to' => $to,
            'ownershipTypes' => $ownershipTypes,
            'businessTypes' => $businessTypes,
            'canCreate' => $canCreate,
            'user' => $user,
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    public function show(Request $request, Store $store): View|RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        if (!$this->canAccessStore($user, $store)) {
            return redirect()->route('management.stores.index')->with('error', 'You do not have access to that store.');
        }

        $store->load(['business', 'posSessions']);

        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();
        $lastMonthStart = $now->copy()->subMonth()->startOfMonth();
        $lastMonthEnd = $now->copy()->subMonth()->endOfMonth();

        // Consolidated transaction revenue aggregates (1 query instead of 3)
        // Use COALESCE(paid_at, created_at) because POS transactions don't set paid_at
        $txStats = \App\Models\Transaction::query()
            ->whereHas('order', fn($q) => $q->where('store_id', $store->id))
            ->where('status', 'confirmed')
            ->selectRaw("
                COALESCE(SUM(amount), 0) as total_revenue,
                COALESCE(SUM(CASE WHEN COALESCE(paid_at, created_at) BETWEEN ? AND ? THEN amount ELSE 0 END), 0) as revenue_this_month,
                COALESCE(SUM(CASE WHEN COALESCE(paid_at, created_at) BETWEEN ? AND ? THEN amount ELSE 0 END), 0) as last_month_revenue
            ", [$startOfMonth, $endOfMonth, $lastMonthStart, $lastMonthEnd])
            ->first();

        $totalRevenue = (int) $txStats->total_revenue;
        $revenueThisMonth = (int) $txStats->revenue_this_month;
        $lastMonthRevenue = (int) $txStats->last_month_revenue;
        $revenueChange = $lastMonthRevenue > 0 ? round((($revenueThisMonth - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1) : ($revenueThisMonth > 0 ? 100 : 0);

        // Consolidated order counts (1 query instead of 3)
        $orderStats = \App\Models\Order::where('store_id', $store->id)
            ->selectRaw("
                COUNT(*) as total,
                SUM(status = 'pending') as pending,
                SUM(status IN ('completed', 'delivered')) as completed
            ")->first();
        $totalOrders = (int) $orderStats->total;
        $pendingOrders = (int) $orderStats->pending;
        $completedOrders = (int) $orderStats->completed;

        // Consolidated product stats (1 query instead of 3)
        $productQuery = \App\Models\Product::where('store_id', $store->id);
        $productStats = (clone $productQuery)->selectRaw("
            COUNT(*) as total,
            SUM(status = 'active') as active,
            COALESCE(SUM(quantity), 0) as total_stock
        ")->first();
        $productCount = (int) $productStats->total;
        $activeProducts = (int) $productStats->active;
        $totalStock = (int) $productStats->total_stock;

        $customerCount = \App\Models\Order::where('store_id', $store->id)
            ->distinct('customer_id')
            ->whereNotNull('customer_id')
            ->count('customer_id');

        $recentOrders = $store->orders()->with('customer')->latest()->take(8)->get();
        $lowStockProducts = \App\Models\Product::where('store_id', $store->id)
            ->where('quantity', '<=', 10)
            ->where('quantity', '>', 0)
            ->where('status', 'active')
            ->latest()
            ->take(6)
            ->get();
        $outOfStock = \App\Models\Product::where('store_id', $store->id)
            ->where('quantity', '<=', 0)
            ->where('status', 'active')
            ->count();

        $activePosSession = \App\Models\PosSession::where('store_id', $store->id)
            ->where('status', \App\Models\PosSession::STATUS_OPEN)
            ->with('staff')
            ->first();

        // Single grouped monthly revenue query instead of 6 separate queries in a loop
        // Use COALESCE(paid_at, created_at) because POS transactions don't set paid_at
        $sixMonthsAgo = $now->copy()->subMonths(6)->startOfMonth();
        $monthlyData = \App\Models\Transaction::query()
            ->whereHas('order', fn($q) => $q->where('store_id', $store->id))
            ->where('status', 'confirmed')
            ->whereRaw('COALESCE(paid_at, created_at) >= ?', [$sixMonthsAgo])
            ->selectRaw("DATE_FORMAT(COALESCE(paid_at, created_at), '%Y-%m') as month, COALESCE(SUM(amount), 0) as total")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $monthlyRevenue = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthKey = $now->copy()->subMonths($i)->format('Y-m');
            $monthlyRevenue[] = [
                'month' => $now->copy()->subMonths($i)->startOfMonth()->format('M'),
                'total' => (int) (($monthlyData->get($monthKey)?->total ?? 0) / 100),
            ];
        }

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Stores', 'url' => route('management.stores.index')],
            ['label' => $store->name],
        ];

        return view('management.stores.show', compact(
            'user', 'store',
            'totalRevenue', 'revenueThisMonth', 'revenueChange',
            'totalOrders', 'pendingOrders', 'completedOrders',
            'productCount', 'activeProducts', 'totalStock', 'customerCount',
            'recentOrders', 'lowStockProducts', 'outOfStock',
            'activePosSession', 'monthlyRevenue', 'breadcrumbs'
        ));
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        if (!$this->canAccessStore($user, $store)) {
            return redirect()->route('management.stores.index')->with('error', 'You do not have access to that store.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string'],
            'instagram_url' => ['nullable', 'url', 'max:255'],
            'facebook_url' => ['nullable', 'url', 'max:255'],
            'twitter_url' => ['nullable', 'url', 'max:255'],
            'tiktok_url' => ['nullable', 'url', 'max:255'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        if (empty($data['slug']) && !empty($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        } elseif (!empty($data['slug'])) {
            $data['slug'] = Str::slug($data['slug']);
        }

        if (!empty($data['slug'])) {
            $base = $data['slug'];
            $slug = $base;
            $tries = 0;
            while (Store::where('slug', $slug)->where('id', '!=', $store->id)->exists()) {
                $suffix = '-' . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
                $slug = $base . $suffix;
                if (++$tries > 10) {
                    break;
                }
            }
            $data['slug'] = $slug;
        }

        if ($request->hasFile('logo')) {
            if ($store->logo_path) {
                try {
                    Storage::disk('public')->delete($store->logo_path);
                } catch (\Throwable $e) {
                    Log::warning('store.logo_delete_failed', [
                        'user_id' => $user->id,
                        'store_id' => $store->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $data['logo_path'] = $request->file('logo')->store('stores/logos', 'public');
        }

        unset($data['logo']);

        $store->update($data);

        $redirectTarget = $request->input('redirect_to');

        return $redirectTarget
            ? redirect($redirectTarget)->with('success', 'Store updated successfully.')
            : redirect()->route('management.stores.settings', $store)->with('success', 'Store updated successfully.');
    }

    public function suspend(Request $request, Store $store): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$this->canAccessStore($user, $store)) {
            return back()->with('error', 'Unauthorized action.');
        }

        $request->validate([
            'reason' => 'nullable|string|max:2000',
        ]);

        $reason = $request->reason ?? 'Suspended by store owner';

        Log::info('store.suspend_requested', [
            'user_id' => $user->id,
            'store_id' => $store->id,
            'reason' => $reason
        ]);

        $store->update(['status' => 'suspended']);

        try {
            if ($user->email) {
                Mail::to($user->email)->queue(new VendorStoreSuspended($store, $reason));
            }
        } catch (\Throwable $e) {
            Log::error('store.suspended_mail_failed', [
                'store_id' => $store->id,
                'error' => $e->getMessage()
            ]);
        }

        return back()->with('success', 'Store suspended successfully.');
    }

    public function activate(Request $request, Store $store): RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$this->canAccessStore($user, $store)) {
            return back()->with('error', 'Unauthorized action.');
        }

        $request->validate([
            'reason' => 'nullable|string|max:2000',
        ]);

        $reason = $request->reason ?? 'Reactivated by store owner';

        Log::info('store.activate_requested', [
            'user_id' => $user->id,
            'store_id' => $store->id,
            'reason' => $reason
        ]);

        $store->update(['status' => 'active']);

        try {
            if ($user->email) {
                Mail::to($user->email)->queue(new VendorStoreReactivated($store, $reason));
            }
        } catch (\Throwable $e) {
            Log::error('store.activated_mail_failed', [
                'store_id' => $store->id,
                'error' => $e->getMessage()
            ]);
        }

        return back()->with('success', 'Store activated successfully.');
    }

    public function destroy(Request $request, Store $store): RedirectResponse
    {
        $user = $request->user();

        if (!$this->canAccessStore($user, $store)) {
            return back()->with('error', 'Unauthorized action.');
        }

        $incompleteOrders = \App\Models\Order::where('store_id', $store->id)
            ->where('status', '!=', \App\Enums\OrderStatus::COMPLETED->value)
            ->exists();
        if ($incompleteOrders) {
            return back()->with('error', 'Cannot delete: store has incomplete orders.');
        }

        $incompleteTransactions = \App\Models\Transaction::whereHas('order', fn($q) => $q->where('store_id', $store->id))
            ->where('status', '!=', \App\Enums\TransactionStatus::CONFIRMED->value)
            ->exists();
        if ($incompleteTransactions) {
            return back()->with('error', 'Cannot delete: store has incomplete transactions.');
        }

        $store->update(['status' => 'deleted']);

        Log::info('store.deleted', ['user_id' => $user->id, 'store_id' => $store->id]);

        return redirect()->route('management.stores.index')
            ->with('success', "Store '{$store->name}' has been deleted.");
    }

    public function webMetrics(Request $request, Store $store): View|RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$this->canAccessStore($user, $store)) {
            abort(403);
        }

        if (!$store->has_website) {
            return redirect()->route('management.stores.show', $store)
                ->with('error', 'This store does not have an online presence.');
        }

        $storeViews = $store->views;

        $productViews = \App\Models\Product::where('store_id', $store->id)->sum('views');

        $webOrders = \App\Models\Order::where('store_id', $store->id)
            ->where('source', 'checkout')
            ->count();

        $webRevenue = (int) \App\Models\Transaction::query()
            ->whereHas('order', fn($q) => $q->where('store_id', $store->id)->where('source', 'checkout'))
            ->where('status', 'confirmed')
            ->sum('amount');

        $topProducts = \App\Models\Product::where('store_id', $store->id)
            ->orderBy('views', 'desc')
            ->take(10)
            ->get(['id', 'name', 'views']);

        $recentActivity = \App\Models\ActivityLog::where('subject_type', \App\Models\Store::class)
            ->where('subject_id', $store->id)
            ->latest()
            ->take(10)
            ->get();

        // Single grouped monthly web orders query instead of 6 separate queries in a loop
        $sixMonthsAgo = now()->subMonths(6)->startOfMonth();
        $monthlyWebData = \App\Models\Order::where('store_id', $store->id)
            ->where('source', 'checkout')
            ->where('created_at', '>=', $sixMonthsAgo)
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month');

        $monthlyWebOrders = [];
        for ($i = 5; $i >= 0; $i--) {
            $monthKey = now()->copy()->subMonths($i)->format('Y-m');
            $monthlyWebOrders[] = [
                'month' => now()->copy()->subMonths($i)->startOfMonth()->format('M'),
                'count' => (int) ($monthlyWebData->get($monthKey)?->count ?? 0),
            ];
        }

        $storeUrl = $store->slug
            ? (config('app.env') === 'local'
                ? url($store->slug)
                : 'https://' . $store->slug . '.storify.ng')
            : null;

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Stores', 'url' => route('management.stores.index')],
            ['label' => $store->name, 'url' => route('management.stores.show', $store)],
            ['label' => 'Web Metrics'],
        ];

        return view('management.stores.web-metrics', compact(
            'user', 'store',
            'storeViews', 'productViews', 'webOrders', 'webRevenue',
            'topProducts', 'recentActivity', 'monthlyWebOrders', 'storeUrl', 'breadcrumbs'
        ));
    }

    public function settings(Request $request, Store $store): View|RedirectResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$this->canAccessStore($user, $store)) {
            abort(403);
        }

        $store->load(['ownershipType', 'businessType', 'business', 'banks', 'deliveryRoutes', 'assignedStaff']);
        $availableStaff = User::where('business_id', $user->business_id)
            ->where('role', 'staff')
            ->where('status', 'active')
            ->whereNotIn('id', $store->assignedStaff->pluck('id'))
            ->with('roles')
            ->get(['id', 'name', 'email']);

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Stores', 'url' => route('management.stores.index')],
            ['label' => $store->name, 'url' => route('management.stores.show', $store)],
            ['label' => 'Settings'],
        ];

        return view('management.stores.settings', compact('user', 'store', 'availableStaff', 'breadcrumbs'));
    }

    public function assignStaff(Request $request, Store $store): RedirectResponse
    {
        $user = $request->user();

        if (!$this->canAccessStore($user, $store)) {
            abort(403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($validated['user_id']);

        if ($user->business_id !== $user->business_id || $user->role !== 'staff') {
            return back()->with('error', 'Invalid staff member.');
        }

        if (!$store->assignedStaff->contains($user->id)) {
            $store->assignedStaff()->attach($user->id);
        }

        return back()->with('success', $user->name . ' assigned to this store.');
    }

    public function enablePos(Request $request, Store $store): RedirectResponse
    {
        $user = $request->user();

        if (!$this->canAccessStore($user, $store)) {
            abort(403);
        }

        $store->update(['pos_enabled' => true]);

        return back()->with('success', 'POS terminal enabled for this store.');
    }

    public function enableWebsite(Request $request, Store $store): RedirectResponse
    {
        $user = $request->user();

        if (!$this->canAccessStore($user, $store)) {
            abort(403);
        }

        $validated = $request->validate([
            'store_name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:stores,slug,' . $store->id,
            'is_nationwide' => 'boolean',
            'nationwide_fee' => 'nullable|numeric|min:0',
            'nationwide_days' => 'nullable|integer|min:1',
        ]);

        $store->update([
            'name' => $validated['store_name'],
            'slug' => $validated['slug'],
            'has_website' => true,
        ]);

        if ($request->boolean('is_nationwide')) {
            $fee = isset($validated['nationwide_fee']) ? (int) $validated['nationwide_fee'] : null;
            $days = isset($validated['nationwide_days']) ? (int) $validated['nationwide_days'] : null;

            if ($fee !== null && $days !== null) {
                $store->deliveryRoutes()->updateOrCreate(
                    ['store_id' => $store->id, 'state' => 'All States', 'country' => 'Nigeria'],
                    [
                        'country' => 'Nigeria',
                        'state' => 'All States',
                        'area' => null,
                        'fee' => $fee * 100,
                        'delivery_days' => $days,
                        'active' => true,
                    ]
                );
            }
        }

        return back()->with('success', 'Web storefront enabled! Your store is now live at ' . $validated['slug'] . '.' . config('app.main_domain', 'storify.ng') . '.');
    }

    public function removeStaff(Request $request, Store $store, User $user): RedirectResponse
    {
        $user = $request->user();

        if (!$this->canAccessStore($user, $store)) {
            abort(403);
        }

        $store->assignedStaff()->detach($user->id);

        return back()->with('success', $user->name . ' removed from this store.');
    }

    private function tabTransactions(Request $request, Store $store, User $user): View
    {
        $query = \App\Models\Transaction::query()
            ->whereHas('order', fn($q) => $q->where('store_id', $store->id))
            ->with(['order.customer', 'paymentMethod']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $transactions = $query->latest()->paginate(15)->withQueryString();

        return view('management.stores.tabs.transactions', compact('user', 'store', 'transactions'));
    }

    private function tabCustomers(Store $store, User $user): View
    {
        $customers = \App\Models\Customer::query()
            ->whereHas('orders', fn($q) => $q->where('store_id', $store->id))
            ->withCount(['orders as orders_count' => fn($q) => $q->where('store_id', $store->id)])
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('management.stores.tabs.customers', compact('user', 'store', 'customers'));
    }

    private function canAccessStore(?User $user, Store $store): bool
    {
        if (!$user) return false;
        if ($user->isStaff()) {
            return $user->assignedStores()->where('stores.id', $store->id)->exists();
        }
        return (int) $store->user_id === (int) $user->id;
    }

    /**
     * AJAX endpoint: load tab content for the store detail page.
     */
    public function loadTab(Request $request, Store $store): View
    {
        $user = $request->user();
        if (!$this->canAccessStore($user, $store)) {
            abort(403);
        }

        $tab = $request->route('tab');

        return match ($tab) {
            'products' => $this->tabProducts($request, $store, $user),
            'orders' => $this->tabOrders($request, $store, $user),
            'settings' => $this->tabSettings($store, $user),
            'staff' => $this->tabStaff($store, $user),
            'transactions' => $this->tabTransactions($request, $store, $user),
            'customers' => $this->tabCustomers($store, $user),
            'web-metrics' => $this->webMetrics($request, $store),
            default => abort(404, 'Unknown tab'),
        };
    }

    private function tabProducts(Request $request, Store $store, User $user): View
    {
        request()->merge(['store_id' => $store->store_id]);

        $selectedStoreId = $store->id;

        $query = Product::where('store_id', $selectedStoreId)
            ->with(['category', 'store', 'images', 'section.warehouse'])
            ->withMin('variants', 'amount')
            ->withMax('variants', 'amount');

        if ($request->filled('q')) {
            $q = trim($request->q);
            $query->where(function ($x) use ($q) {
                $x->where('name', 'like', "%{$q}%")
                  ->orWhere('product_code', 'like', "%{$q}%");
            });
        }

        if ($request->filled('status') && in_array($request->status, ['active', 'inactive'])) {
            $query->where('status', $request->status);
        }

        $products = $query->latest()->paginate($request->input('per_page', 10))->withQueryString();

        // Compute display prices (simplified — amounts in base currency)
        $currencies = Currency::query()->get(['id', 'code', 'symbol'])->keyBy('id');
        $displayPrices = [];
        foreach ($products as $prod) {
            $cur = $currencies[$prod->currency_id ?? 0] ?? null;
            $sym = $cur->symbol ?? '';
            $amt = (float) ($prod->amount ?? 0);
            if ($prod->has_variants && $prod->variants_min_amount !== null) {
                $min = (float) $prod->variants_min_amount;
                $max = (float) ($prod->variants_max_amount ?? $min);
                if ($min == $max) {
                    $displayPrices[$prod->id] = $sym . number_format($min, 2);
                } else {
                    $displayPrices[$prod->id] = $sym . number_format($min, 2) . ' - ' . $sym . number_format($max, 2);
                }
            } else {
                $displayPrices[$prod->id] = $sym . number_format($amt, 2);
            }
        }

        return view('management.stores.tabs.products', compact('user', 'store', 'products', 'displayPrices'));
    }

    private function tabOrders(Request $request, Store $store, User $user): View
    {
        request()->merge(['store_id' => $store->store_id]);

        $query = Order::query()
            ->with(['customer', 'store', 'items', 'staff'])
            ->where('store_id', $store->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                  ->orWhereHas('customer', fn($q) => $q->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
            });
        }

        $orders = $query->latest()->paginate(15)->withQueryString();

        return view('management.stores.tabs.orders', compact('user', 'store', 'orders'));
    }

    private function tabSettings(Store $store, User $user): View
    {
        $store->load(['ownershipType', 'businessType', 'business', 'banks', 'deliveryRoutes', 'assignedStaff']);
        $availableStaff = User::where('business_id', $user->business_id)
            ->where('role', 'staff')
            ->where('status', 'active')
            ->whereNotIn('id', $store->assignedStaff->pluck('id'))
            ->with('roles')
            ->get(['id', 'name', 'email']);

        return view('management.stores.tabs.settings', compact('user', 'store', 'availableStaff'));
    }

    private function tabStaff(Store $store, User $user): View
    {
        $staff = User::where('business_id', $user->business_id)
            ->whereIn('role', ['staff', 'business_owner'])
            ->where('status', '!=', 'deleted')
            ->whereHas('assignedStores', fn($q) => $q->where('assignmentable_id', $store->id))
            ->with('roles', 'assignedStores', 'assignedWarehouses')
            ->latest()
            ->get();

        return view('management.stores.tabs.staff', compact('user', 'store', 'staff'));
    }
}
