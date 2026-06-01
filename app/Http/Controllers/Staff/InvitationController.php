<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function showAccept(string $token): View|RedirectResponse
    {
        $user = User::where('invitation_token', $token)->first();

        if (!$user) {
            return redirect()->route('management.auth.login')
                ->with('error', 'Invalid or expired invitation link.');
        }

        if ($user->status !== 'invited') {
            return redirect()->route('management.auth.login')
                ->with('warning', 'This invitation has already been accepted.');
        }

        return view('staff.auth.accept-invitation', compact('user', 'token'));
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $user = User::where('invitation_token', $token)->first();

        if (!$user) {
            return redirect()->route('management.auth.login')
                ->with('error', 'Invalid or expired invitation link.');
        }

        if ($user->status !== 'invited') {
            return redirect()->route('management.auth.login')
                ->with('warning', 'This invitation has already been accepted.');
        }

        $validated = $request->validate([
            'password' => 'required|min:8|confirmed',
        ]);

        $user->update([
            'password' => $validated['password'],
            'invitation_token' => null,
            'accepted_at' => now(),
            'status' => 'active',
            'force_password_change' => false,
        ]);

        Log::info('staff.invitation.accepted', ['user_id' => $user->id, 'email' => $user->email]);

        Auth::guard('web')->login($user);

        $routeName = $user->hasRole('Cashier') ? 'staff.pos' : 'management.dashboard';

        return redirect()->route($routeName)
            ->with('success', 'Welcome aboard! Your account has been activated.');
    }
}
