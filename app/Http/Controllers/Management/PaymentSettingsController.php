<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreBank;
use App\Services\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // Business-level payment methods (from business_payment_method pivot)
        $methods = \App\Models\PaymentMethod::whereHas('businesses', fn($q) => $q->where('business_id', $user->business_id))
            ->with(['businesses' => fn($q) => $q->where('business_id', $user->business_id)])
            ->get();

        // Count assigned stores per method
        foreach ($methods as $m) {
            $m->assigned_count = $m->stores()
                ->whereIn('stores.id', $user->stores()->pluck('id'))
                ->wherePivot('is_active', true)->count();
        }

        // Unconfigured methods (available but not yet set up for this business)
        $availableMethods = \App\Models\PaymentMethod::active()
            ->whereDoesntHave('businesses', fn($q) => $q->where('business_id', $user->business_id))
            ->get();

        // Banks list for the add bank modal
        try {
            $banks = $this->paystackService->getBanks()['data'] ?? [];
        } catch (\Throwable $e) {
            $banks = [];
        }

        $stores = $user->stores()->where('status', '!=', 'deleted')->orderBy('name')->get();

        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Payment Settings']];

        return view('management.payment-settings.index', compact('user', 'methods', 'availableMethods', 'banks', 'stores', 'breadcrumbs'));
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

        if (StoreBank::where('business_id', $user->business_id)->where('account_number', $validated['account_number'])->where('bank_code', $validated['bank_code'])->exists()) {
            return redirect()->route('management.payment-settings.index')
                ->with('error', 'This bank account already exists.');
        }

        $bank = StoreBank::create([
            'business_id' => $user->business_id,
            'bank_name' => $validated['bank_name'],
            'bank_code' => $validated['bank_code'],
            'account_number' => $validated['account_number'],
            'account_name' => $validated['account_name'],
            'is_verified' => true,
        ]);

        // Register bank_transfer as a business payment method (if not already)
        $bid = \App\Models\PaymentMethod::where('code', 'bank_transfer')->value('id');
        DB::table('business_payment_method')->insertOrIgnore([
            'business_id' => $user->business_id, 'payment_method_id' => $bid,
            'is_active' => true, 'config' => json_encode(['bank_name' => $bank->bank_name, 'account_number' => $bank->account_number, 'account_name' => $bank->account_name]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // If a store was selected, assign via pivot
        $storeId = $validated['store_id'] ?? null;
        if ($storeId) {
            $store = $user->stores()->findOrFail($storeId);
            DB::table('store_payment_method')->insertOrIgnore([
                'store_id' => $store->id, 'payment_method_id' => $bid, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

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

    private function paystackPivotId(): int { return \App\Models\PaymentMethod::where('code', 'paystack')->value('id'); }

    public function storePaystackKeys(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate(['public_key' => 'required|string|max:255', 'secret_key' => 'required|string|max:255']);
        $pid = $this->paystackPivotId();

        $exists = DB::table('business_payment_method')->where('business_id', $user->business_id)->where('payment_method_id', $pid)->exists();
        if ($exists) {
            return redirect()->route('management.payment-settings.index')->with('error', 'Paystack is already connected. Edit the existing keys instead.');
        }

        DB::table('business_payment_method')->insert([
            'business_id' => $user->business_id, 'payment_method_id' => $pid,
            'is_active' => true, 'config' => json_encode(['public_key' => $validated['public_key'], 'secret_key' => $validated['secret_key']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Log::info('payment-settings.business_paystack_added', ['user_id' => $user->id]);
        return redirect()->route('management.payment-settings.index')->with('success', 'Paystack connected.');
    }

    public function updatePaystackKeys(Request $request, $id): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate(['public_key' => 'required|string|max:255', 'secret_key' => 'required|string|max:255']);
        DB::table('business_payment_method')->where('id', $id)->where('business_id', $user->business_id)->update([
            'config' => json_encode(['public_key' => $validated['public_key'], 'secret_key' => $validated['secret_key']]),
            'updated_at' => now(),
        ]);
        return redirect()->route('management.payment-settings.index')->with('success', 'Paystack keys updated.');
    }

    public function destroyPaystackKeys(Request $request, $id): RedirectResponse
    {
        $user = $request->user();
        $row = DB::table('business_payment_method')->where('id', $id)->where('business_id', $user->business_id)->firstOrFail();
        DB::table('business_payment_method')->where('id', $id)->delete();
        DB::table('store_payment_method')->where('payment_method_id', $row->payment_method_id)->delete();
        return redirect()->route('management.payment-settings.index')->with('success', 'Removed.');
    }

    public function togglePaystackKeys(Request $request, $id): RedirectResponse
    {
        $user = $request->user();
        $row = DB::table('business_payment_method')->where('id', $id)->where('business_id', $user->business_id)->first();
        DB::table('business_payment_method')->where('id', $id)->update(['is_active' => !$row->is_active, 'updated_at' => now()]);
        return redirect()->route('management.payment-settings.index')->with('success', 'Paystack ' . (!$row->is_active ? 'enabled' : 'disabled') . '.');
    }

    public function testGateway(Request $request, $id): JsonResponse
    {
        $user = $request->user();
        $row = DB::table('business_payment_method')->where('id', $id)->where('business_id', $user->business_id)->firstOrFail();
        $config = json_decode($row->config, true);
        $gw = (object)['public_key' => $config['public_key'] ?? '', 'secret_key' => $config['secret_key'] ?? ''];
        try {
            $result = $this->paystackService->usingGateway($gw)->testConnection();
            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    public function methodInfo(Request $request, $type, $id): View
    {
        $user = $request->user();

        $gateway = null;
        $bank = null;

        if ($type === 'gateway') {
            $gateway = DB::table('business_payment_method')->where('id', $id)->where('business_id', $user->business_id)->firstOrFail();
        } elseif ($type === 'bank') {
            $pivot = DB::table('business_payment_method')->where('id', $id)->where('business_id', $user->business_id)->firstOrFail();
            $cfg = json_decode($pivot->config, true);
            $bank = (object)['bank_name' => $cfg['bank_name'] ?? 'N/A', 'account_number' => $cfg['account_number'] ?? 'N/A', 'account_name' => $cfg['account_name'] ?? ''];
        } else {
            abort(404);
        }

        if ($gateway) {
            $gConfig = json_decode($gateway->config, true);
            $typeLabel = 'Gateway — Paystack';
            $name = 'Paystack (' . (isset($gConfig['public_key']) ? substr($gConfig['public_key'], 0, 7) . '****' . substr($gConfig['public_key'], -4) : 'N/A') . ')';
            $gateway->is_active = (bool) $gateway->is_active;
            $gateway->masked_public_key = $name;
            // Only stores that have this gateway active
            $pid = $this->paystackPivotId();
            $assignedStores = $user->stores()->where('status', '!=', 'deleted')
                ->whereHas('paymentMethods', fn($q) => $q->where('payment_method_id', $pid)->where('store_payment_method.is_active', true))
                ->orderBy('name')->get();
            $availableStores = $user->stores()->where('status', '!=', 'deleted')
                ->whereDoesntHave('paymentMethods', fn($q) => $q->where('payment_method_id', $pid)->where('store_payment_method.is_active', true))
                ->orderBy('name')->get();
        } elseif ($bank) {
            $typeLabel = 'Bank Account';
            $name = $bank->bank_name . ' — ' . $bank->account_number;
            $bid = \App\Models\PaymentMethod::where('code', 'bank_transfer')->value('id');
            $assignedStores = $user->stores()->where('status', '!=', 'deleted')
                ->whereHas('paymentMethods', fn($q) => $q->where('payment_method_id', $bid)->where('store_payment_method.is_active', true))
                ->orderBy('name')->get();
            $availableStores = $user->stores()->where('status', '!=', 'deleted')
                ->whereDoesntHave('paymentMethods', fn($q) => $q->where('payment_method_id', $bid)->where('store_payment_method.is_active', true))
                ->orderBy('name')->get();
        } else {
            abort(404);
        }

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Payment Settings', 'url' => route('management.payment-settings.index')],
            ['label' => $name],
        ];

        return view('management.payment-settings.method-info', compact('user', 'gateway', 'bank', 'typeLabel', 'name', 'assignedStores', 'availableStores', 'breadcrumbs'));
    }

    public function assignStore(Request $request, $id, $type): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate(['store_id' => 'required|exists:stores,id']);

        $store = $user->stores()->findOrFail($validated['store_id']);

        if ($type === 'gateway') {
            $pid = $this->paystackPivotId();
            DB::table('store_payment_method')->insertOrIgnore([
                'store_id' => $store->id, 'payment_method_id' => $pid, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } elseif ($type === 'bank') {
            $bid = \App\Models\PaymentMethod::where('code', 'bank_transfer')->value('id');
            DB::table('store_payment_method')->insertOrIgnore([
                'store_id' => $store->id, 'payment_method_id' => $bid, 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return redirect()->route('management.payment-settings.method-info', ['type' => $type, 'id' => $id])
            ->with('success', 'Assigned to ' . $store->name . '.');
    }

    public function unassignStore(Request $request, $id, $type, $store_id): RedirectResponse
    {
        $user = $request->user();
        $store = $user->stores()->findOrFail($store_id);
        $pid = $type === 'gateway' ? $this->paystackPivotId() : \App\Models\PaymentMethod::where('code', 'bank_transfer')->value('id');
        DB::table('store_payment_method')->where('store_id', $store->id)->where('payment_method_id', $pid)->delete();
        return redirect()->route('management.payment-settings.method-info', ['type' => $type, 'id' => $id])
            ->with('success', 'Removed from ' . $store->name . '.');
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
            $pid = $this->paystackPivotId();
            $hasPaystack = DB::table('store_payment_method')->where('store_id', $store->id)
                ->where('payment_method_id', $pid)->where('is_active', true)->exists();
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
