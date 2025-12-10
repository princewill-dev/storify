<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\Store\VendorOnboardRequest;
use App\Mail\VendorStoreCreated;
use App\Mail\AdminStoreCreated;
use App\Models\BusinessType;
use App\Models\OwnershipType;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class VendorOnboardController extends Controller
{
    public function showStoreCreationForm(Request $request, Vendor $routeVendor): View|RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor || $vendor->account_id !== $routeVendor->account_id) {
            Log::error('vendor.kyc.store_form_mismatch', [
                'route_vendor_account_id' => $routeVendor->account_id,
                'vendor_account_id' => $vendor?->account_id,
            ]);
            abort(503, 'Unable to verify your access to create a store.');
        }

        $ownershipTypes = OwnershipType::orderBy('name')->get(['id', 'name']);
        $businessTypes = BusinessType::orderBy('name')->get(['id', 'name']);

        return view('vendors.auth.create-store', [
            'vendor' => $vendor,
            'ownershipTypes' => $ownershipTypes,
            'businessTypes' => $businessTypes,
        ]);
    }

    public function submitOnboardingStore(VendorOnboardRequest $request, Vendor $routeVendor): RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor || $vendor->account_id !== $routeVendor->account_id) {
            Log::error('vendor.kyc.store_submit_mismatch', [
                'route_vendor_account_id' => $routeVendor->account_id,
                'vendor_account_id' => $vendor?->account_id,
            ]);
            abort(503, 'Unable to verify your access to submit this store.');
        }

        $data = $request->validated();

        Log::info('vendor.kyc.store_submission_received', [
            'vendor_id' => $vendor->id,
            'account_id' => $vendor->account_id,
            'data' => ['name' => $data['name']],
        ]);

        $baseSlug = Str::slug($data['name']);
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

        $data['support_email'] = $data['support_email'] ?? $vendor->email;
        $data['support_phone'] = $data['support_phone'] ?? $vendor->phone;
        $data['address'] = $data['address'] ?? $vendor->location;
        $data['ownership_type_id'] = $data['ownership_type_id'] ?? $vendor->ownership_type_id;
        $data['business_type_id'] = $data['business_type_id'] ?? $vendor->business_type_id;
        $data['vendor_id'] = $vendor->id;
        $data['status'] = Store::STATUS_PENDING;

        try {
            $store = Store::create($data);
            Log::info('vendor.kyc.onboarding_store_created', [
                'vendor_id' => $vendor->id,
                'store_id' => $store->id,
                'store_public_id' => $store->store_id,
            ]);
        } catch (\Throwable $e) {
            if (!empty($data['logo_path'])) {
                try {
                    Storage::disk('public')->delete($data['logo_path']);
                } catch (\Throwable $cleanup) {
                    Log::warning('vendor.kyc.onboarding_store_logo_cleanup_failed', [
                        'vendor_id' => $vendor->id,
                        'error' => $cleanup->getMessage(),
                    ]);
                }
            }

            Log::error('vendor.kyc.onboarding_store_create_failed', [
                'vendor_id' => $vendor->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withInput()->with('error', 'We could not create your store right now. Please try again.');
        }

        if (!empty($vendor->email)) {
            Mail::to($vendor->email)->queue(new VendorStoreCreated($store));
        }

        $admins = $this->adminRecipients();
        if (!empty($admins)) {
            Mail::to($admins)->queue(new AdminStoreCreated($store));
        }

        session(['onboarding_store_id' => $store->id]);

        return redirect()->route('vendor.kyc.store.success', ['vendor' => $vendor])
            ->with('success', 'Store profile created! Our team will review and notify you once it is active.');
    }

    public function showStoreSuccess(Request $request, Vendor $routeVendor): View|RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor || $vendor->account_id !== $routeVendor->account_id) {
            Log::error('vendor.kyc.store_success_mismatch', [
                'route_vendor_account_id' => $routeVendor->account_id,
                'vendor_account_id' => $vendor?->account_id,
            ]);
            abort(503, 'Unable to verify your access to this page.');
        }

        $storeId = session('onboarding_store_id');
        if (!$storeId) {
            return redirect()->route('vendor.kyc.store.create', ['vendor' => $vendor])
                ->with('error', 'Create your store to continue.');
        }

        $store = Store::where('id', $storeId)->where('vendor_id', $vendor->id)->first();
        if (!$store) {
            return redirect()->route('vendor.kyc.store.create', ['vendor' => $vendor])
                ->with('error', 'We could not find the store you just created. Please try again.');
        }

        session()->forget('onboarding_store_id');

        $storeUrl = null;
        if (!empty($store->slug) && app('router')->has('home.store.products.index')) {
            try {
                $storeUrl = route('home.store.products.index', ['store_slug' => $store->slug]);
            } catch (\Throwable $e) {
                Log::warning('vendor.onboarding_store_success_url_failed', [
                    'store_id' => $store->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return view('vendors.auth.success', ['store' => $store, 'storeUrl' => $storeUrl]);
    }

    private function adminRecipients(): array
    {
        $emails = \App\Models\User::where('role', 'superadmin')->pluck('email')->filter()->all();

        if (empty($emails) && config('mail.from.address')) {
            $emails = [config('mail.from.address')];
        }

        return $emails;
    }
}
