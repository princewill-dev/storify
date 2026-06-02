<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\StorePaymentGateway;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class PaymentSettingsController extends Controller
{
    protected PaystackService $paystackService;

    public function __construct(PaystackService $paystackService)
    {
        $this->paystackService = $paystackService;
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        $stores = $user->stores()->with(['banks', 'paymentGateways'])->orderBy('name')->get();

        $banks = $this->paystackService->getBanks()['data'] ?? [];

        return view('management.payment-settings.index', compact('user', 'stores', 'banks'));
    }

    public function storeBankAccount(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'store_id' => 'nullable|exists:stores,id',
            'bank_code' => 'required|string',
            'bank_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:20',
            'account_name' => 'required|string|max:255',
        ]);

        $storeId = $validated['store_id'] ?? null;
        if ($storeId) {
            Store::where('id', $storeId)->where('user_id', $user->id)->firstOrFail();
            $existingCount = StoreBank::where('store_id', $storeId)->count();
        } else {
            $existingCount = 0;
        }

        StoreBank::create([
            'store_id' => $storeId,
            'business_id' => $user->business_id,
            'bank_name' => $validated['bank_name'],
            'bank_code' => $validated['bank_code'],
            'account_number' => $validated['account_number'],
            'account_name' => $validated['account_name'],
            'is_primary' => $existingCount === 0,
            'is_verified' => true,
        ]);

        Log::info('payment-settings.bank_added', ['user_id' => $user->id, 'store_id' => $storeId]);

        return redirect()->route('management.payment-settings.index')
            ->with('success', 'Bank account added successfully.');
    }

    public function updateBankAccount(Request $request, StoreBank $bank): RedirectResponse
    {
        $user = $request->user();
        $storeIds = $user->stores()->pluck('id');

        if ($bank->store_id && !$storeIds->contains($bank->store_id)) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'store_id' => 'nullable|exists:stores,id',
            'bank_code' => 'required|string',
            'bank_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:20',
            'account_name' => 'required|string|max:255',
            'is_primary' => 'boolean',
        ]);

        $newStoreId = $validated['store_id'] ?? null;

        if ($newStoreId && !$storeIds->contains($newStoreId)) {
            abort(403, 'Unauthorized');
        }

        if ($request->boolean('is_primary')) {
            StoreBank::where('store_id', $newStoreId)->where('id', '!=', $bank->id)
                ->update(['is_primary' => false]);
        }

        $bank->update([
            'store_id' => $newStoreId,
            'bank_name' => $validated['bank_name'],
            'bank_code' => $validated['bank_code'],
            'account_number' => $validated['account_number'],
            'account_name' => $validated['account_name'],
            'is_primary' => $request->boolean('is_primary'),
        ]);

        return redirect()->route('management.payment-settings.index')
            ->with('success', 'Bank account updated successfully.');
    }

    public function destroyBankAccount(Request $request, StoreBank $bank): RedirectResponse
    {
        $user = $request->user();
        $storeIds = $user->stores()->pluck('id');

        if ($bank->store_id && !$storeIds->contains($bank->store_id)) {
            abort(403, 'Unauthorized');
        }

        $bank->delete();

        return redirect()->route('management.payment-settings.index')
            ->with('success', 'Bank account deleted successfully.');
    }

    public function storePaystackKeys(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'public_key' => 'required|string|max:255',
            'secret_key' => 'required|string|max:255',
        ]);

        $store = Store::where('id', $validated['store_id'])->where('user_id', $user->id)->firstOrFail();

        $existing = StorePaymentGateway::where('store_id', $store->id)
            ->where('gateway', 'paystack')->first();

        if ($existing) {
            return redirect()->route('management.payment-settings.index')
                ->with('error', 'Paystack keys already exist for this store. Edit them instead.');
        }

        StorePaymentGateway::create([
            'store_id' => $store->id,
            'business_id' => $store->business_id,
            'gateway' => 'paystack',
            'public_key' => $validated['public_key'],
            'secret_key' => $validated['secret_key'],
            'is_active' => true,
        ]);

        Log::info('payment-settings.paystack_added', ['user_id' => $user->id, 'store_id' => $store->id]);

        return redirect()->route('management.payment-settings.index')
            ->with('success', 'Paystack keys added for ' . $store->name . '.');
    }

    public function updatePaystackKeys(Request $request, StorePaymentGateway $gateway): RedirectResponse
    {
        $user = $request->user();
        $storeIds = $user->stores()->pluck('id');

        if ($gateway->store_id && !$storeIds->contains($gateway->store_id)) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'public_key' => 'required|string|max:255',
            'secret_key' => 'required|string|max:255',
        ]);

        if (!$storeIds->contains($validated['store_id'])) {
            abort(403, 'Unauthorized');
        }

        $gateway->update([
            'store_id' => $validated['store_id'],
            'public_key' => $validated['public_key'],
            'secret_key' => $validated['secret_key'],
        ]);

        return redirect()->route('management.payment-settings.index')
            ->with('success', 'Paystack keys updated successfully.');
    }

    public function destroyPaystackKeys(Request $request, StorePaymentGateway $gateway): RedirectResponse
    {
        $user = $request->user();
        $storeIds = $user->stores()->pluck('id');

        if ($gateway->store_id && !$storeIds->contains($gateway->store_id)) {
            abort(403, 'Unauthorized');
        }

        $gateway->delete();

        return redirect()->route('management.payment-settings.index')
            ->with('success', 'Paystack keys removed.');
    }

    public function togglePaystackKeys(Request $request, StorePaymentGateway $gateway): RedirectResponse
    {
        $user = $request->user();
        $storeIds = $user->stores()->pluck('id');

        if ($gateway->store_id && !$storeIds->contains($gateway->store_id)) {
            abort(403, 'Unauthorized');
        }

        $gateway->update(['is_active' => !$gateway->is_active]);

        return redirect()->route('management.payment-settings.index')
            ->with('success', 'Paystack ' . ($gateway->is_active ? 'enabled' : 'disabled') . ' for this store.');
    }

    public function testGateway(Request $request, StorePaymentGateway $gateway): JsonResponse
    {
        $user = $request->user();
        $storeIds = $user->stores()->pluck('id');

        if ($gateway->store_id && !$storeIds->contains($gateway->store_id)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        try {
            $result = $this->paystackService->usingGateway($gateway)->testConnection();
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function verifyBankAccount(Request $request): JsonResponse
    {
        $request->validate([
            'account_number' => 'required|string|size:10',
            'bank_code' => 'required|string',
        ]);

        $result = $this->paystackService->resolveAccountNumber(
            $request->input('account_number'),
            $request->input('bank_code')
        );

        if ($result['success']) {
            return response()->json(['success' => true, 'account_name' => $result['data']['account_name'] ?? null]);
        }

        return response()->json(['success' => false, 'message' => $result['message'] ?? 'Could not verify account']);
    }

    public function togglePaymentMode(Request $request, Store $store): RedirectResponse
    {
        $user = $request->user();

        if ($store->user_id !== $user->id) {
            abort(403, 'Unauthorized');
        }

        $request->validate(['payment_mode' => 'required|in:auto,manual']);

        $newMode = $request->input('payment_mode');

        if ($newMode === 'auto') {
            $hasPaystack = StorePaymentGateway::where('store_id', $store->id)
                ->where('gateway', 'paystack')->where('is_active', true)->exists();
            if (!$hasPaystack) {
                return back()->with('error', 'Add active Paystack keys first.');
            }
        }

        if ($newMode === 'manual') {
            $hasBank = StoreBank::where('store_id', $store->id)->exists();
            if (!$hasBank) {
                return back()->with('error', 'Add a bank account first.');
            }
        }

        $store->update(['payment_mode' => $newMode]);

        return back()->with('success', $store->name . ' payment mode set to ' . ($newMode === 'auto' ? 'Auto (Card)' : 'Manual (Transfer)') . '.');
    }
}
