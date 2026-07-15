<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\PosSession;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PosController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->force_password_change) {
            return redirect()->route('staff.password.change');
        }

        // Superadmins get all POS-enabled stores; staff get assigned ones
        $assignedStores = ($user->isPlatformAdmin() ?? false)
            ? Store::where('pos_enabled', true)->where('status', '!=', 'deleted')->get()
            : $user->assignedStores()->where('pos_enabled', true)->where('status', '!=', 'deleted')->get();

        if ($assignedStores->isEmpty()) {
            return view('staff.pos.no-store');
        }

        // If cashier has multiple stores and no active store selected, show picker
        $activeStoreId = session('staff_active_store_id');
        if ($assignedStores->count() > 1 && !$activeStoreId) {
            return view('staff.pos.select-store', ['assignedStores' => $assignedStores, 'user' => $user]);
        }

        $activeStore = $activeStoreId
            ? $assignedStores->where('id', $activeStoreId)->first()
            : $assignedStores->first();

        if ($activeStore) {
            session(['staff_active_store_id' => $activeStore->id]);
        }

        $activeSession = $activeStore
            ? PosSession::where('store_id', $activeStore->id)
                ->where('status', PosSession::STATUS_OPEN)
                ->where('staff_id', $user->id)
                ->latest()
                ->first()
            : null;

        $products = $activeStore
            ? \App\Models\Product::where('store_id', $activeStore->id)->where('status', 'active')->where('quantity', '>', 0)
                ->with(['images' => fn($q) => $q->orderBy('position')])
                ->latest()->take(30)->get()
            : collect();

        $canProcessSale = $user->can('pos process_sale');
        $canOpenSession = $user->can('pos open_session');
        $canCloseSession = $user->can('pos close_session');

        $recentOrders = collect();
        if ($activeStore) {
            $orderQuery = \App\Models\Order::where('store_id', $activeStore->id)
                ->where('source', 'pos')
                ->with(['items', 'transactions.paymentMethod']);
            if ($activeSession) {
                $orderQuery->where('pos_session_id', $activeSession->id);
            } else {
                $orderQuery->where('staff_id', $user->id);
            }
            $recentOrders = $orderQuery->latest()->take(30)->get();
        }

        $paymentMethods = [];
        $paystackKey = null;
        $bankAccounts = [];

        if ($activeStore && $canProcessSale) {

            $pid = \App\Models\PaymentMethod::where('code', 'paystack')->value('id');
            $sid = DB::table('store_payment_method')->where('store_id', $activeStore->id)
                ->where('payment_method_id', $pid)->where('is_active', true)->exists();
            $bizRow = DB::table('business_payment_method')->where('business_id', $activeStore->business_id)
                ->where('payment_method_id', $pid)->where('is_active', true)->first();
            $paystack = null;
            if ($bizRow) {
                $cfg = json_decode($bizRow->config, true);
                $paystack = (object)['public_key' => $cfg['public_key'] ?? null];
            }

                if ($paystack) {
                    $paymentMethods[] = ['id' => 'paystack', 'label' => 'Paystack', 'icon' => 'credit-card'];
                    $paystackKey = $paystack->public_key;
                }

            if ($activeStore->banks()->exists()) {
                $paymentMethods[] = ['id' => 'transfer', 'label' => 'Bank Transfer', 'icon' => 'building'];
                $bankAccounts = $activeStore->banks()->where('is_verified', true)->get();
            }
        }

        return view('staff.pos.index', compact(
            'user',
            'assignedStores',
            'activeStore',
            'activeSession',
            'products',
            'recentOrders',
            'canProcessSale',
            'canOpenSession',
            'canCloseSession',
            'paymentMethods',
            'paystackKey',
            'bankAccounts',
        ));
    }

    public function switchStore(Request $request)
    {
        $request->validate(['store_id' => 'required|exists:stores,id']);

        $user = $request->user();

        $assignedStore = $user->assignedStores()->where('stores.id', $request->store_id)->where('status', '!=', 'deleted')->exists();

        if (!$assignedStore) {
            return back()->with('error', 'You are not assigned to this store.');
        }

        session(['staff_active_store_id' => $request->store_id]);

        return back()->with('success', 'Store switched.');
    }

    public function showPasswordChange(Request $request): View
    {
        $user = $request->user();
        return view('staff.auth.change-password', compact('user'));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'password' => 'required|min:8|confirmed',
        ]);

        $user->update([
            'password' => $validated['password'],
            'force_password_change' => false,
        ]);

        $route = $this->staffRedirectRoute($user);

        return redirect()->to($route)
            ->with('success', 'Password updated successfully. Welcome!');
    }

    private function staffRedirectRoute(\App\Models\User $user): string
    {
        $roles = $user->getRoleNames();
        if ($roles->count() === 1 && $roles->contains('Cashier')) {
            $hasPosStore = $user->assignedStores()->where('pos_enabled', true)->exists();
            return $hasPosStore ? route('pos.index') : route('pos.no-store');
        }
        return route('management.dashboard');
    }
}
