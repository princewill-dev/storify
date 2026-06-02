<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\PosSession;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PosSessionController extends Controller
{
    public function index(Request $request, Store $store): View
    {
        $user = $request->user();
        if (!$user || $store->user_id !== $user->id) {
            abort(403);
        }

        $sessions = $store->posSessions()->with('staff')->latest()->paginate(20);

        return view('management.pos.sessions.index', compact('user', 'store', 'sessions'));
    }

    public function show(Request $request, Store $store, PosSession $session): View
    {
        $user = $request->user();
        if (!$user || $store->user_id !== $user->id || $session->store_id !== $store->id) {
            abort(403);
        }

        $session->load('orders.items', 'staff');

        return view('management.pos.sessions.show', compact('user', 'store', 'session'));
    }

    public function open(Request $request, Store $store): RedirectResponse
    {
        $user = $request->user();
        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        if (!$store->pos_enabled) {
            return back()->with('error', 'POS is not enabled for this store.');
        }

        $existingSession = PosSession::where('store_id', $store->id)
            ->where('staff_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->exists();

        if ($existingSession) {
            return back()->with('error', 'You already have an open session for this store.');
        }

        $validated = $request->validate([
            'opening_balance' => 'required|integer|min:0',
        ]);

        PosSession::create([
            'store_id' => $store->id,
            'business_id' => $store->business_id,
            'staff_id' => $user->id,
            'opened_at' => now(),
            'opening_balance' => $validated['opening_balance'],
            'status' => PosSession::STATUS_OPEN,
        ]);

        return back()->with('success', 'POS session opened successfully.');
    }

    public function close(Request $request, Store $store): RedirectResponse
    {
        $user = $request->user();
        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        $session = PosSession::where('store_id', $store->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->latest()
            ->first();

        if (!$session) {
            return back()->with('error', 'No open session found.');
        }

        $validated = $request->validate([
            'closing_balance_actual' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $session->close(
            $validated['closing_balance_actual'],
            $validated['notes'] ?? null
        );

        return back()
            ->with('success', 'POS session closed. Difference: ₦' . number_format($session->difference / 100, 2));
    }
}
