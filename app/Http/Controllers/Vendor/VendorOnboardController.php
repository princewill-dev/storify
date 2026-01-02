<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\Store\VendorOnboardRequest;
use App\Mail\VendorStoreCreated;
use App\Mail\AdminStoreCreated;
use App\Models\BusinessType;
use App\Models\DeliveryRoute;
use App\Models\OwnershipType;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

use App\Services\PaystackService;

class VendorOnboardController extends Controller
{
    protected PaystackService $paystackService;

    public function __construct(PaystackService $paystackService)
    {
        $this->paystackService = $paystackService;
    }

    /**
     * Get list of supported banks
     */
    public function getBanks(): JsonResponse
    {
        $result = $this->paystackService->getBanks();
        
        return response()->json($result);
    }

    /**
     * Resolve bank account details
     */
    public function validateBank(Request $request): JsonResponse
    {
        $request->validate([
            'account_number' => 'required|string',
            'bank_code' => 'required|string',
        ]);

        $result = $this->paystackService->resolveAccountNumber(
            $request->account_number,
            $request->bank_code
        );

        return response()->json($result);
    }
    
    /**
     * Check if a store slug is available.
     */
    public function checkSlugAvailability(Request $request): JsonResponse
    {
        $rawInput = $request->input('slug', '');
        
        Log::info('vendor.slug.check_request', [
            'raw_input' => $rawInput,
            'ip' => $request->ip(),
        ]);
        
        // Sanitize: convert to lowercase, replace spaces with hyphens, remove invalid chars
        $slug = Str::slug($rawInput);
        
        if (strlen($slug) < 2) {
            $response = [
                'available' => false,
                'slug' => $slug,
                'message' => 'Store link must be at least 2 characters',
            ];
            Log::info('vendor.slug.check_response', ['result' => 'too_short', 'response' => $response]);
            return response()->json($response);
        }
        
        if (strlen($slug) > 60) {
            $response = [
                'available' => false,
                'slug' => $slug,
                'message' => 'Store link is too long',
            ];
            Log::info('vendor.slug.check_response', ['result' => 'too_long', 'response' => $response]);
            return response()->json($response);
        }
        
        // Check if slug exists
        $exists = Store::where('slug', $slug)->exists();
        
        if ($exists) {
            // Suggest an alternative
            $counter = 1;
            $suggestedSlug = $slug;
            while (Store::where('slug', $suggestedSlug)->exists()) {
                $suggestedSlug = $slug . '-' . $counter++;
            }
            
            $response = [
                'available' => false,
                'slug' => $slug,
                'suggested' => $suggestedSlug,
                'message' => 'This store link is already taken',
            ];
            Log::info('vendor.slug.check_response', ['result' => 'taken', 'response' => $response]);
            return response()->json($response);
        }
        
        $response = [
            'available' => true,
            'slug' => $slug,
            'message' => 'Store link is available!',
        ];
        Log::info('vendor.slug.check_response', ['result' => 'available', 'response' => $response]);
        return response()->json($response);
    }

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

        // Use pre-validated slug from form if provided, otherwise generate
        if (!empty($request->input('slug')) && !Store::where('slug', $request->input('slug'))->exists()) {
            $data['slug'] = Str::slug($request->input('slug'));
        } else {
            // Fallback: generate slug from name
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
        }

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
            DB::beginTransaction();

            $store = Store::create($data);

            // Create store bank account
            $store->banks()->create([
                'bank_name' => $request->input('bank_name'),
                'bank_code' => $request->input('bank_code'),
                'account_number' => $request->input('account_number'),
                'account_name' => $request->input('account_name'),
                'is_primary' => true,
                'is_verified' => true, // Already verified via API before submission
            ]);

            DB::commit();

            Log::info('vendor.kyc.onboarding_store_created', [
                'vendor_id' => $vendor->id,
                'store_id' => $store->id,
                'store_public_id' => $store->store_id,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
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

        return redirect()->route('vendor.kyc.delivery-routes.form', ['vendor' => $vendor])
            ->with('success', 'Store profile created! Now set up your delivery routes.');
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

        // If vendor already has active subscription (e.g. via early pass), show success page
        if ($vendor->hasActiveSubscription()) {
            Log::info('vendor.onboarding.store_success_shown', [
                'vendor_id' => $vendor->id,
                'store_id' => $store->id,
            ]);

            // Generate store URL
            $storeUrl = null;
            if ($store->slug) {
                $storeUrl = 'https://' . $store->slug . '.' . config('app.main_domain');
            }

            return view('vendors.auth.success', [
                'store' => $store,
                'storeUrl' => $storeUrl,
            ]);
        }

        Log::info('vendor.onboarding.store_success_redirect_to_subscription', [
            'vendor_id' => $vendor->id,
            'store_id' => $store->id,
        ]);

        return redirect()->route('vendor.subscription.plan', ['vendor' => $vendor])
            ->with('success', 'Store created successfully! Please complete your subscription to activate your account.');
    }

    public function showDeliveryRoutesForm(Request $request, Vendor $routeVendor): View|RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor || $vendor->account_id !== $routeVendor->account_id) {
            abort(503, 'Unable to verify your access to this page.');
        }

        $storeId = session('onboarding_store_id');
        if (!$storeId) {
            return redirect()->route('vendor.kyc.store.create', ['vendor' => $vendor])
                ->with('error', 'Create your store first to set up delivery routes.');
        }

        $store = Store::where('id', $storeId)->where('vendor_id', $vendor->id)->first();
        if (!$store) {
            return redirect()->route('vendor.kyc.store.create', ['vendor' => $vendor])
                ->with('error', 'Store not found.');
        }

        // Load existing delivery routes if any
        $existingRoutes = $store->deliveryRoutes;

        return view('vendors.auth.set-delivery-routes', [
            'vendor' => $vendor,
            'store' => $store,
            'existingRoutes' => $existingRoutes,
        ]);
    }

    public function saveDeliveryRoutes(Request $request, Vendor $routeVendor): RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor || $vendor->account_id !== $routeVendor->account_id) {
            abort(503, 'Unable to verify your access to submit delivery routes.');
        }

        $storeId = session('onboarding_store_id');
        if (!$storeId) {
            return redirect()->route('vendor.kyc.store.create', ['vendor' => $vendor])
                ->with('error', 'Create your store first.');
        }

        $store = Store::where('id', $storeId)->where('vendor_id', $vendor->id)->first();
        if (!$store) {
            return redirect()->route('vendor.kyc.store.create', ['vendor' => $vendor])
                ->with('error', 'Store not found.');
        }

        // Validate the routes
        $validated = $request->validate([
            'routes' => 'nullable|array',
            'routes.*.country' => 'required|string|max:255',
            'routes.*.state' => 'required|string|max:255',
            'routes.*.area' => 'nullable|string|max:255',
            'routes.*.fee' => 'required|numeric|min:0',
            'routes.*.delivery_days' => 'required|integer|min:1',
            'routes.*.active' => 'boolean',
        ]);

        try {
            DB::beginTransaction();

            // Delete existing routes for this store
            $store->deliveryRoutes()->delete();

            // Create new routes if provided
            if (!empty($validated['routes'])) {
                foreach ($validated['routes'] as $routeData) {
                    $store->deliveryRoutes()->create([
                        'country' => $routeData['country'],
                        'state' => $routeData['state'],
                        'area' => $routeData['area'] ?? null,
                        'fee' => $routeData['fee'] * 100, // Convert to kobo
                        'delivery_days' => $routeData['delivery_days'],
                        'active' => $routeData['active'] ?? true,
                    ]);
                }
            }

            DB::commit();

            Log::info('vendor.delivery_routes_saved', [
                'vendor_id' => $vendor->id,
                'store_id' => $store->id,
                'routes_count' => count($validated['routes'] ?? []),
            ]);

            // Clear the onboarding session
            session()->forget('onboarding_store_id');

            // Redirect to subscription page
            return redirect()->route('vendor.subscription.plan', ['vendor' => $vendor])
                ->with('success', 'Delivery routes saved! Please complete your subscription to activate your account.');
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('vendor.delivery_routes_save_failed', [
                'vendor_id' => $vendor->id,
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withInput()->with('error', 'Could not save delivery routes. Please try again.');
        }
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
