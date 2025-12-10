<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\BusinessType;
use App\Models\Category;
use App\Models\OwnershipType;
use App\Models\Pack;
use App\Models\Product;
use App\Models\Store;
use App\Models\Vendor;
use App\Http\Requests\Vendor\Store\CreateStoreRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StoreController extends Controller
{
    public function create(Request $request, Vendor $routeVendor): View|RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor || $vendor->id !== $routeVendor->id) {
            return redirect()->route('vendor.auth.login');
        }

        if (!$vendor->is_verified) {
            return redirect()->route('vendor.auth.verify-otp')
                ->with('warning', 'Please verify your email to continue.');
        }

        if (!in_array($vendor->kyc_status, [Vendor::KYC_STATUS_SUBMITTED, Vendor::KYC_STATUS_APPROVED], true)) {
            return redirect()->route('vendor.kyc.show', $vendor)
                ->with('warning', 'Submit your KYC details before creating your store.');
        }

        $ownershipTypes = OwnershipType::orderBy('name')->get(['id', 'name']);
        $businessTypes = BusinessType::orderBy('name')->get(['id', 'name']);

        $prefill = session('pending_store_defaults', []);
        $defaults = [
            'name' => $prefill['name'] ?? $vendor->name,
            'support_email' => $prefill['support_email'] ?? $vendor->email,
            'support_phone' => $prefill['support_phone'] ?? $vendor->phone,
            'address' => $prefill['address'] ?? ($vendor->location ?? ''),
        ];

        Log::info('vendor.store.create_viewed', [
            'vendor_id' => $vendor->id,
        ]);

        return view('vendors.auth.create', compact('vendor', 'ownershipTypes', 'businessTypes', 'defaults'));
    }

    public function store(CreateStoreRequest $request, Vendor $routeVendor): RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor || $vendor->id !== $routeVendor->id) {
            return redirect()->route('vendor.auth.login');
        }

        if (!$vendor->is_verified) {
            return redirect()->route('vendor.auth.verify-otp')
                ->with('warning', 'Please verify your email to continue.');
        }

        if (!in_array($vendor->kyc_status, [Vendor::KYC_STATUS_SUBMITTED, Vendor::KYC_STATUS_APPROVED], true)) {
            return redirect()->route('vendor.kyc.show', $vendor)
                ->with('warning', 'Submit your KYC details before creating your store.');
        }

        $pendingDefaults = session('pending_store_defaults', []);
        $hadStores = $vendor->stores()->exists();

        $data = $request->validated();

        $data['support_email'] = $data['support_email'] ?? $pendingDefaults['support_email'] ?? $vendor->email;
        $data['support_phone'] = $data['support_phone'] ?? $pendingDefaults['support_phone'] ?? $vendor->phone;
        $data['address'] = $data['address'] ?? $pendingDefaults['address'] ?? $vendor->location;
        $data['ownership_type_id'] = $data['ownership_type_id'] ?? $vendor->ownership_type_id;
        $data['business_type_id'] = $data['business_type_id'] ?? $vendor->business_type_id;

        $baseSlug = !empty($data['slug']) ? Str::slug($data['slug']) : Str::slug($data['name']);
        if ($baseSlug === '') {
            $baseSlug = Str::random(8);
        }

        $slug = $baseSlug;
        $counter = 1;
        while (Store::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }
        $data['slug'] = $slug;

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('stores/logos', 'public');
        }

        unset($data['logo']);

        $data['vendor_id'] = $vendor->id;
        $data['status'] = 'inactive';

        try {
            $store = Store::create($data);
            Log::info('vendor.store.created', [
                'vendor_id' => $vendor->id,
                'store_id' => $store->id,
                'store_public_id' => $store->store_id,
            ]);
        } catch (\Throwable $e) {
            if (!empty($data['logo_path'] ?? null)) {
                try {
                    Storage::disk('public')->delete($data['logo_path']);
                } catch (\Throwable $cleanup) {
                    Log::warning('vendor.store.logo_cleanup_failed', [
                        'vendor_id' => $vendor->id,
                        'error' => $cleanup->getMessage(),
                    ]);
                }
            }

            Log::error('vendor.store.create_failed', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withInput()->with('error', 'We could not create your store right now. Please try again.');
        }

        if (!$hadStores) {
            session()->forget('pending_store_defaults');

            return redirect()->route('vendor.stores.success', ['vendor' => $vendor, 'store' => $store])
                ->with('success', 'Store profile created! Our team will review and notify you when it is ready.');
        }

        return redirect()->route('vendor.stores.success', ['vendor' => $vendor, 'store' => $store])
            ->with('success', 'Store profile created! Our team will review and notify you when it is ready.');
    }

    public function success(Request $request, Vendor $routeVendor, Store $store): View|RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor || $vendor->id !== $routeVendor->id) {
            return redirect()->route('vendor.auth.login');
        }

        if ((int) $store->vendor_id !== (int) $vendor->id) {
            Log::warning('vendor.store.success_unauthorized', [
                'vendor_id' => $vendor->id,
                'store_id' => $store->id,
            ]);
            return redirect()->route('vendor.stores.create', $vendor)->with('error', 'You do not have access to that store.');
        }

        $storeUrl = null;
        if (!empty($store->slug) && app('router')->has('home.store.products.index')) {
            try {
                $storeUrl = route('home.store.products.index', ['store_slug' => $store->slug]);
            } catch (\Throwable $e) {
                Log::warning('vendor.store.success_url_generation_failed', [
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return view('vendors.auth.success', [
            'store' => $store,
            'storeUrl' => $storeUrl,
        ]);
    }

    public function index(Request $request): View|RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        $status = strtolower((string) $request->query('status', ''));
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');

        $storesQuery = $vendor->stores()->with(['ownershipType', 'businessType']);

        if (in_array($status, ['active', 'inactive', 'suspended', 'deleted'], true)) {
            $storesQuery->where('status', $status);
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
        $canCreate = $vendor->canCreateMoreStores();

        return view('vendors.stores.index', [
            'stores' => $stores,
            'status' => $request->query('status'),
            'q' => $q,
            'from' => $from,
            'to' => $to,
            'ownershipTypes' => $ownershipTypes,
            'businessTypes' => $businessTypes,
            'canCreate' => $canCreate,
            'vendor' => $vendor,
        ]);
    }

    public function show(Request $request, Vendor $routeVendor, Store $store): View|RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        if ((int) $vendor->id !== (int) $routeVendor->id) {
            return redirect()->route('vendor.auth.login');
        }

        if ((int) $store->vendor_id !== (int) $vendor->id) {
            return redirect()->route('vendor.stores.index', ['vendor' => $vendor])->with('error', 'You do not have access to that store.');
        }

        $store->load(['ownershipType', 'businessType', 'vendor']);
        $productCount = Product::where('store_id', $store->id)->count();
        $recentProducts = Product::where('store_id', $store->id)->latest()->take(10)->get();
        $categories = Category::where('store_id', $store->id)->orderBy('name')->get();
        $packs = Pack::where('store_id', $store->id)->latest()->take(10)->get();
        $ownershipTypes = OwnershipType::orderBy('name')->get(['id', 'name']);
        $businessTypes = BusinessType::orderBy('name')->get(['id', 'name']);

        return view('vendors.stores.show', [
            'store' => $store,
            'productCount' => $productCount,
            'recentProducts' => $recentProducts,
            'categories' => $categories,
            'packs' => $packs,
            'ownershipTypes' => $ownershipTypes,
            'businessTypes' => $businessTypes,
            'vendor' => $vendor,
        ]);
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        if ((int) $store->vendor_id !== (int) $vendor->id) {
            return redirect()->route('vendor.stores.index')->with('error', 'You do not have access to that store.');
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
            'ownership_type_id' => ['nullable', 'exists:ownership_types,id'],
            'business_type_id' => ['nullable', 'exists:business_types,id'],
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
                    Log::warning('vendor.store.logo_delete_failed', [
                        'vendor_id' => $vendor->id,
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
            : redirect()->route('vendor.stores.show', $store)->with('success', 'Store updated successfully.');
    }
}
