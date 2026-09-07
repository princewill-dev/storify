<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AdminInvitationController extends Controller
{
    public function showAccept(string $token): View|RedirectResponse
    {
        $user = User::where('invitation_token', $token)
            ->whereIn('role', ['admin', 'superadmin'])
            ->first();

        if (! $user) {
            return redirect()->route('admin.login')
                ->with('error', 'This invitation link is invalid or has expired.');
        }

        if ($user->status !== 'invited') {
            return redirect()->route('admin.login')
                ->with('warning', 'This invitation has already been accepted. Please log in.');
        }

        return view('admin.auth.accept-invitation', compact('user', 'token'));
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $user = User::where('invitation_token', $token)
            ->whereIn('role', ['admin', 'superadmin'])
            ->first();

        if (! $user) {
            return redirect()->route('admin.login')
                ->with('error', 'This invitation link is invalid or has expired.');
        }

        if ($user->status !== 'invited') {
            return redirect()->route('admin.login')
                ->with('warning', 'This invitation has already been accepted. Please log in.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user->update([
            'name' => $validated['name'],
            'password' => $validated['password'],
            'invitation_token' => null,
            'accepted_at' => now(),
            'status' => 'active',
            'is_verified' => true,
            'force_password_change' => false,
        ]);

        Log::info('admin.invitation.accepted', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        Auth::guard('web')->login($user);

        return redirect()->route('admin.dashboard')
            ->with('success', 'Welcome to the admin team, ' . $user->name . '!');
    }
}
