<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\StorePaymentGateway;
use App\Models\Vendor;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class VendorPaymentSettingsController extends Controller
{
    protected PaystackService $paystackService;

    public function __construct(PaystackService $paystackService)
    {
        $this->paystackService = $paystackService;
    }

    /**
     * Display payment settings page
     */
    public function index(Request $request, Vendor $vendor): View
    {
        $authVendor = $request->user('vendor');
        if (!$authVendor || $authVendor->id !== $vendor->id) {
            abort(403, 'Unauthorized');
        }

        $stores = $vendor->stores()->get();
        $storeIds = $stores->pluck('id');

        // Get bank accounts for all vendor's stores
        $bankAccounts = StoreBank::whereIn('store_id', $storeIds)
            ->with('store')
            ->orderBy('created_at', 'desc')
            ->get();

        // Get payment gateways for all vendor's stores
        $paymentGateways = StorePaymentGateway::whereIn('store_id', $storeIds)
            ->with('store')
            ->orderBy('created_at', 'desc')
            ->get();

        // Get list of banks for dropdown
        $banks = $this->paystackService->getBanks()['data'] ?? [];

        return view('vendors.payment_settings.index', compact(
            'vendor',
            'stores',
            'bankAccounts',
            'paymentGateways',
            'banks'
        ));
    }

    /**
     * Store a new bank account
     */
    public function storeBankAccount(Request $request, Vendor $vendor): RedirectResponse
    {
        $authVendor = $request->user('vendor');
        if (!$authVendor || $authVendor->id !== $vendor->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'bank_code' => 'required|string',
            'bank_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:20',
            'account_name' => 'required|string|max:255',
        ]);

        // Verify store belongs to vendor
        $store = Store::where('id', $validated['store_id'])
            ->where('vendor_id', $vendor->id)
            ->firstOrFail();

        // Check if this is the first bank account for this store
        $existingCount = StoreBank::where('store_id', $store->id)->count();

        StoreBank::create([
            'store_id' => $store->id,
            'bank_name' => $validated['bank_name'],
            'bank_code' => $validated['bank_code'],
            'account_number' => $validated['account_number'],
            'account_name' => $validated['account_name'],
            'is_primary' => $existingCount === 0,
            'is_verified' => true,
        ]);

        Log::info('vendor.payment_settings.bank_added', [
            'vendor_id' => $vendor->id,
            'store_id' => $store->id,
        ]);

        return redirect()->route('vendor.payment-settings.index', ['vendor' => $vendor])
            ->with('success', 'Bank account added successfully.');
    }

    /**
     * Update a bank account
     */
    public function updateBankAccount(Request $request, Vendor $vendor, StoreBank $bank): RedirectResponse
    {
        $authVendor = $request->user('vendor');
        if (!$authVendor || $authVendor->id !== $vendor->id) {
            abort(403, 'Unauthorized');
        }

        // Verify bank belongs to vendor's store
        $storeIds = $vendor->stores()->pluck('id');
        if (!$storeIds->contains($bank->store_id)) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'bank_code' => 'required|string',
            'bank_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:20',
            'account_name' => 'required|string|max:255',
            'is_primary' => 'boolean',
        ]);

        // Verify new store belongs to vendor
        if (!$storeIds->contains($validated['store_id'])) {
            abort(403, 'Unauthorized');
        }

        // If setting as primary, unset others
        if ($request->boolean('is_primary')) {
            StoreBank::where('store_id', $validated['store_id'])
                ->where('id', '!=', $bank->id)
                ->update(['is_primary' => false]);
        }

        $bank->update([
            'store_id' => $validated['store_id'],
            'bank_name' => $validated['bank_name'],
            'bank_code' => $validated['bank_code'],
            'account_number' => $validated['account_number'],
            'account_name' => $validated['account_name'],
            'is_primary' => $request->boolean('is_primary'),
        ]);

        return redirect()->route('vendor.payment-settings.index', ['vendor' => $vendor])
            ->with('success', 'Bank account updated successfully.');
    }

    /**
     * Delete a bank account
     */
    public function destroyBankAccount(Request $request, Vendor $vendor, StoreBank $bank): RedirectResponse
    {
        $authVendor = $request->user('vendor');
        if (!$authVendor || $authVendor->id !== $vendor->id) {
            abort(403, 'Unauthorized');
        }

        // Verify bank belongs to vendor's store
        $storeIds = $vendor->stores()->pluck('id');
        if (!$storeIds->contains($bank->store_id)) {
            abort(403, 'Unauthorized');
        }

        $bank->delete();

        return redirect()->route('vendor.payment-settings.index', ['vendor' => $vendor])
            ->with('success', 'Bank account deleted successfully.');
    }

    /**
     * Store Paystack API keys
     */
    public function storePaystackKeys(Request $request, Vendor $vendor): RedirectResponse
    {
        $authVendor = $request->user('vendor');
        if (!$authVendor || $authVendor->id !== $vendor->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'public_key' => 'required|string|max:255',
            'secret_key' => 'required|string|max:255',
        ]);

        // Verify store belongs to vendor
        $store = Store::where('id', $validated['store_id'])
            ->where('vendor_id', $vendor->id)
            ->firstOrFail();

        // Check if gateway already exists for this store
        $existing = StorePaymentGateway::where('store_id', $store->id)
            ->where('gateway', 'paystack')
            ->first();

        if ($existing) {
            return redirect()->route('vendor.payment-settings.index', ['vendor' => $vendor])
                ->with('error', 'Paystack keys already exist for this store. Please edit instead.');
        }

        StorePaymentGateway::create([
            'store_id' => $store->id,
            'gateway' => 'paystack',
            'public_key' => $validated['public_key'],
            'secret_key' => $validated['secret_key'],
            'is_active' => true,
        ]);

        Log::info('vendor.payment_settings.paystack_added', [
            'vendor_id' => $vendor->id,
            'store_id' => $store->id,
        ]);

        return redirect()->route('vendor.payment-settings.index', ['vendor' => $vendor])
            ->with('success', 'Paystack API keys added successfully.');
    }

    /**
     * Update Paystack API keys
     */
    public function updatePaystackKeys(Request $request, Vendor $vendor, StorePaymentGateway $gateway): RedirectResponse
    {
        $authVendor = $request->user('vendor');
        if (!$authVendor || $authVendor->id !== $vendor->id) {
            abort(403, 'Unauthorized');
        }

        // Verify gateway belongs to vendor's store
        $storeIds = $vendor->stores()->pluck('id');
        if (!$storeIds->contains($gateway->store_id)) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'public_key' => 'required|string|max:255',
            'secret_key' => 'required|string|max:255',
        ]);

        // Verify new store belongs to vendor
        if (!$storeIds->contains($validated['store_id'])) {
            abort(403, 'Unauthorized');
        }

        $gateway->update([
            'store_id' => $validated['store_id'],
            'public_key' => $validated['public_key'],
            'secret_key' => $validated['secret_key'],
        ]);

        return redirect()->route('vendor.payment-settings.index', ['vendor' => $vendor])
            ->with('success', 'Paystack API keys updated successfully.');
    }

    /**
     * Delete Paystack API keys
     */
    public function destroyPaystackKeys(Request $request, Vendor $vendor, StorePaymentGateway $gateway): RedirectResponse
    {
        $authVendor = $request->user('vendor');
        if (!$authVendor || $authVendor->id !== $vendor->id) {
            abort(403, 'Unauthorized');
        }

        // Verify gateway belongs to vendor's store
        $storeIds = $vendor->stores()->pluck('id');
        if (!$storeIds->contains($gateway->store_id)) {
            abort(403, 'Unauthorized');
        }

        $gateway->delete();

        return redirect()->route('vendor.payment-settings.index', ['vendor' => $vendor])
            ->with('success', 'Paystack API keys deleted successfully.');
    }

    /**
     * Toggle Paystack API keys active status
     */
    public function togglePaystackKeys(Request $request, Vendor $vendor, StorePaymentGateway $gateway): RedirectResponse
    {
        $authVendor = $request->user('vendor');
        if (!$authVendor || $authVendor->id !== $vendor->id) {
            abort(403, 'Unauthorized');
        }

        // Verify gateway belongs to vendor's store
        $storeIds = $vendor->stores()->pluck('id');
        if (!$storeIds->contains($gateway->store_id)) {
            abort(403, 'Unauthorized');
        }

        $gateway->update(['is_active' => !$gateway->is_active]);

        $status = $gateway->is_active ? 'enabled' : 'disabled';

        return redirect()->route('vendor.payment-settings.index', ['vendor' => $vendor])
            ->with('success', "Paystack keys {$status} successfully.");
    }

    /**
     * Verify bank account via Paystack API (AJAX)
     */
    public function verifyBankAccount(Request $request, Vendor $vendor): JsonResponse
    {
        $authVendor = $request->user('vendor');
        if (!$authVendor || $authVendor->id !== $vendor->id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'account_number' => 'required|string|size:10',
            'bank_code' => 'required|string',
        ]);

        $result = $this->paystackService->resolveAccountNumber(
            $request->input('account_number'),
            $request->input('bank_code')
        );

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'account_name' => $result['data']['account_name'] ?? null,
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message'] ?? 'Could not verify account',
        ]);
    }

    /**
     * Toggle store payment mode between auto (Paystack) and manual (Bank Transfer)
     */
    public function togglePaymentMode(Request $request, Vendor $vendor, Store $store): RedirectResponse
    {
        $authVendor = $request->user('vendor');
        if (!$authVendor || $authVendor->id !== $vendor->id) {
            abort(403, 'Unauthorized');
        }

        // Verify store belongs to vendor
        if ($store->vendor_id !== $vendor->id) {
            abort(403, 'Unauthorized');
        }

        $newMode = $request->input('payment_mode');
        
        // Validate the mode
        if (!in_array($newMode, ['auto', 'manual'])) {
            return redirect()->route('vendor.payment-settings.index', ['vendor' => $vendor])
                ->with('error', 'Invalid payment mode.');
        }

        // If switching to auto, check if Paystack keys exist
        if ($newMode === 'auto') {
            $hasPaystack = StorePaymentGateway::where('store_id', $store->id)
                ->where('gateway', 'paystack')
                ->where('is_active', true)
                ->exists();
            
            if (!$hasPaystack) {
                return redirect()->route('vendor.payment-settings.index', ['vendor' => $vendor])
                    ->with('error', 'Please add Paystack API keys first before enabling Auto mode.');
            }
        }

        // If switching to manual, check if bank account exists
        if ($newMode === 'manual') {
            $hasBank = StoreBank::where('store_id', $store->id)->exists();
            
            if (!$hasBank) {
                return redirect()->route('vendor.payment-settings.index', ['vendor' => $vendor])
                    ->with('error', 'Please add a bank account first before enabling Manual mode.');
            }
        }

        $store->update(['payment_mode' => $newMode]);

        $modeLabel = $newMode === 'auto' ? 'Auto (Paystack)' : 'Manual (Bank Transfer)';

        Log::info('vendor.payment_settings.mode_changed', [
            'vendor_id' => $vendor->id,
            'store_id' => $store->id,
            'new_mode' => $newMode,
        ]);

        return redirect()->route('vendor.payment-settings.index', ['vendor' => $vendor])
            ->with('success', "Payment mode for {$store->name} changed to {$modeLabel}.");
    }
}
