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
        \Log::info('[Bank Loading] getBanks method called');
        
        try {
            $result = $this->paystackService->getBanks();
            
            \Log::info('[Bank Loading] Paystack service returned', [
                'status' => $result['status'] ?? null,
                'data_count' => isset($result['data']) ? count($result['data']) : 0,
                'result' => $result
            ]);
            
            return response()->json($result);
        } catch (\Exception $e) {
            \Log::error('[Bank Loading] Exception in getBanks', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'status' => false,
                'message' => 'Failed to fetch banks: ' . $e->getMessage()
            ], 500);
        }
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

        // No session needed - we'll query the database in next steps
        return redirect()->route('vendor.store.success', ['vendor' => $vendor])
            ->with('success', 'Store created successfully!');
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

        // Get the latest store from database (no session needed)
        $store = $vendor->stores()->latest()->first();
        if (!$store) {
            return redirect()->route('vendor.store.create', ['vendor' => $vendor])
                ->with('error', 'Please create your store first.');
        }
        
        // Show success page with store details
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
            'vendor' => $vendor,
            'store' => $store,
            'storeUrl' => $storeUrl,
        ]);
    }

    public function showDeliveryRoutesForm(Request $request, Vendor $routeVendor): View|RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor || $vendor->account_id !== $routeVendor->account_id) {
            abort(503, 'Unable to verify your access to this page.');
        }

        // Get the latest store from database (no session needed)
        $store = $vendor->stores()->latest()->first();
        if (!$store) {
            return redirect()->route('vendor.store.create', ['vendor' => $vendor])
                ->with('error', 'Please create your store first.');
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

        // Get the latest store from database (no session needed)
        $store = $vendor->stores()->latest()->first();
        if (!$store) {
            return redirect()->route('vendor.store.create', ['vendor' => $vendor])
                ->with('error', 'Please create your store first.');
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
                        'area' => $routeData['area'] ?? '',
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

            // Redirect to subscription page (no session clearing needed)
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

    /**
     * Show payment methods configuration page
     */
    public function showPaymentMethods(Vendor $vendor): View|RedirectResponse
    {
        $store = $vendor->stores()->latest()->first();
        
        if (!$store) {
            return redirect()->route('vendor.store.create', ['vendor' => $vendor])
                ->with('error', 'Please create a store first.');
        }

        // Check if payment method already configured
        $hasBank = $store->banks()->exists();
        $hasPaystack = $store->paymentGateways()->where('gateway', 'paystack')->exists();
        
        $configuredMethod = null;
        $configuredData = null;
        
        if ($hasBank) {
            $configuredMethod = 'bank';
            $configuredData = $store->banks()->first();
        } elseif ($hasPaystack) {
            $configuredMethod = 'paystack';
            $configuredData = $store->paymentGateways()->where('gateway', 'paystack')->first();
        }

        return view('vendors.auth.set-payment-methods', compact('vendor', 'store', 'configuredMethod', 'configuredData'));
    }

    /**
     * Store bank as payment method
     */
    public function storePaymentBank(Request $request, Vendor $vendor): RedirectResponse
    {
        $store = $vendor->stores()->latest()->first();
        
        if (!$store) {
            return redirect()->route('vendor.store.create', ['vendor' => $vendor])
                ->with('error', 'Please create a store first.');
        }

        try {
            $validated = $request->validate([
                'bank_name' => 'required|string|max:255',
                'bank_code' => 'required|string|max:50',
                'account_number' => 'required|string|max:20',
                'account_name' => 'required|string|max:255',
            ]);

            // Create bank account as primary
            $store->banks()->create(array_merge($validated, [
                'is_primary' => true,
                'is_verified' => true
            ]));

            return redirect()->route('vendor.payment-methods.form', ['vendor' => $vendor])
                ->with('success', 'Bank account added successfully!');
                
        } catch (\Exception $e) {
            \Log::error('[Payment Methods] Bank save failed', [
                'error' => $e->getMessage(),
                'vendor_id' => $vendor->id,
                'store_id' => $store->id
            ]);
            
            return back()->withInput()->with('error', 'Failed to add bank account. Please try again.');
        }
    }

    /**
     * Store Paystack payment gateway configuration
     */
    public function storePaymentPaystack(Request $request, Vendor $vendor): RedirectResponse
    {
        $store = $vendor->stores()->latest()->first();
        
        if (!$store) {
            \Log::error('[Payment Methods] Paystack save - No store found', [
                'vendor_id' => $vendor->id
            ]);
            return redirect()->route('vendor.store.create', ['vendor' => $vendor]);
        }

        \Log::info('[Payment Methods] Paystack configuration started', [
            'vendor_id' => $vendor->id,
            'store_id' => $store->id,
            'store_name' => $store->name,
        ]);

        try {
            $validated = $request->validate([
                'public_key' => 'required|string|starts_with:pk_',
                'secret_key' => 'required|string|starts_with:sk_',
            ]);

            \Log::info('[Payment Methods] Paystack keys validated (format)', [
                'vendor_id' => $vendor->id,
                'public_key_prefix' => substr($validated['public_key'], 0, 7),
                'secret_key_prefix' => substr($validated['secret_key'], 0, 7),
            ]);

            // Test API keys with Paystack handshake (skip in development)
            $skipValidation = config('app.debug') || config('app.env') === 'local';
            $validationWarning = null;
            
            if (!$skipValidation) {
                \Log::info('[Payment Methods] Testing Paystack keys with API handshake', [
                    'vendor_id' => $vendor->id,
                ]);

                $paystackService = app(\App\Services\PaystackService::class);
                $testResult = $paystackService->testApiKeys(
                    $validated['secret_key'],
                    $validated['public_key']
                );

                if (!$testResult['success']) {
                    // Check if it's a network error (timeout/connection issue)
                    $isNetworkError = isset($testResult['error']) && 
                        (str_contains($testResult['error'], 'timeout') || 
                         str_contains($testResult['error'], 'cURL') ||
                         str_contains($testResult['error'], 'Could not resolve'));

                    if ($isNetworkError) {
                        \Log::warning('[Payment Methods] Paystack validation skipped due to network error', [
                            'vendor_id' => $vendor->id,
                            'error' => $testResult['error'] ?? $testResult['message'],
                        ]);
                        
                        // Allow saving but show warning
                        $validationWarning = 'Keys saved but could not be validated due to network issues. Please ensure they are correct.';
                    } else {
                        // Invalid keys error
                        \Log::warning('[Payment Methods] Paystack key validation failed', [
                            'vendor_id' => $vendor->id,
                            'error_message' => $testResult['message'],
                            'error_code' => $testResult['error_code'] ?? null,
                        ]);

                        return back()
                            ->withInput()
                            ->with('error', $testResult['message']);
                    }
                } else {
                    \Log::info('[Payment Methods] Paystack keys validated successfully', [
                        'vendor_id' => $vendor->id,
                        'store_id' => $store->id,
                        'test_response' => $testResult['data']['test_response'] ?? 'Success',
                    ]);
                }
            } else {
                \Log::info('[Payment Methods] Paystack validation skipped (development environment)', [
                    'vendor_id' => $vendor->id,
                    'app_env' => config('app.env'),
                ]);
                $validationWarning = 'Keys saved without validation (development mode). Ensure they are correct for production.';
            }

            // Delete existing Paystack config if any
            $existingCount = $store->paymentGateways()->where('gateway', 'paystack')->count();
            if ($existingCount > 0) {
                \Log::info('[Payment Methods] Removing existing Paystack configuration', [
                    'vendor_id' => $vendor->id,
                    'store_id' => $store->id,
                    'existing_count' => $existingCount,
                ]);
                $store->paymentGateways()->where('gateway', 'paystack')->delete();
            }

            // Create new Paystack configuration with encrypted keys
            $gateway = $store->paymentGateways()->create([
                'gateway' => 'paystack',
                'public_key' => $validated['public_key'],
                'secret_key' => $validated['secret_key'],
                'is_active' => true,
                'metadata' => [
                    'configured_at' => now()->toDateTimeString(),
                    'validated' => true,
                ],
            ]);

            \Log::info('[Payment Methods] Paystack configuration saved successfully', [
                'vendor_id' => $vendor->id,
                'store_id' => $store->id,
                'gateway_id' => $gateway->id,
                'public_key_masked' => $gateway->masked_public_key,
                'validation_skipped' => !is_null($validationWarning),
            ]);

            $redirect = redirect()->route('vendor.payment-methods.form', ['vendor' => $vendor]);
            
            if ($validationWarning) {
                return $redirect->with('warning', $validationWarning);
            }
            
            return $redirect->with('success', 'Paystack integration configured successfully!');
                
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::warning('[Payment Methods] Paystack validation failed', [
                'vendor_id' => $vendor->id,
                'errors' => $e->errors(),
            ]);
            throw $e;
            
        } catch (\Exception $e) {
            \Log::error('[Payment Methods] Paystack save failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'vendor_id' => $vendor->id,
                'store_id' => $store->id
            ]);
            
            return back()->withInput()->with('error', 'Failed to configure Paystack. Please check your keys and try again.');
        }
    }

    /**
     * Skip payment methods configuration
     */
    public function skipPaymentMethods(Vendor $vendor): RedirectResponse
    {
        return redirect()->route('vendor.delivery-routes.form', ['vendor' => $vendor]);
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
