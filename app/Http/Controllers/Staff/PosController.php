<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\PosSession;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PosController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->force_password_change) {
            return redirect()->route('staff.password.change');
        }

        $assignedStores = $user->assignedStores()->where('pos_enabled', true)->get();

        if ($assignedStores->isEmpty()) {
            return view('staff.pos.no-store');
        }

        $activeStoreId = session('staff_active_store_id');
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
            ? \App\Models\Product::where('store_id', $activeStore->id)->where('status', 'active')->where('quantity', '>', 0)->latest()->take(20)->get()
            : collect();

        $canProcessSale = $user->can('pos process_sale');
        $canOpenSession = $user->can('pos open_session');
        $canCloseSession = $user->can('pos close_session');

        return view('staff.pos.index', compact(
            'user',
            'assignedStores',
            'activeStore',
            'activeSession',
            'products',
            'canProcessSale',
            'canOpenSession',
            'canCloseSession',
        ));
    }

    public function switchStore(Request $request)
    {
        $request->validate(['store_id' => 'required|exists:stores,id']);

        $user = $request->user();

        $assignedStore = $user->assignedStores()->where('id', $request->store_id)->exists();

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

        $routeName = $user->hasRole('Cashier') ? 'staff.pos' : 'management.dashboard';

        return redirect()->route($routeName)
            ->with('success', 'Password updated successfully. Welcome!');
    }
}
