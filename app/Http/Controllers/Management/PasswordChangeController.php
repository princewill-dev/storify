<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PasswordChangeController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user->force_password_change) {
            return redirect()->route('management.dashboard');
        }

        return view('management.auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->update([
            'password' => $validated['password'],
            'force_password_change' => false,
        ]);

        Log::info('management.password_changed', ['user_id' => $user->id]);

        return redirect()->route('management.dashboard')
            ->with('success', 'Password updated successfully. Welcome back!');
    }
}
