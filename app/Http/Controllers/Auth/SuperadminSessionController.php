<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session login for the log viewer.
 *
 * Every other consumer of this API authenticates with a Sanctum token, but the
 * log viewer is opened by typing a URL — and a top-level browser navigation
 * cannot carry an Authorization header. A session guard is therefore the only
 * thing that works, which is why the `web` guard and the session cookie exist
 * at all in an otherwise API-only app.
 *
 * The session it establishes opens exactly one surface: /logs. Nothing else in
 * the application reads the `web` guard.
 */
class SuperadminSessionController extends Controller
{
    public function show(): View
    {
        return view('auth.log-viewer-login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        $user = Auth::guard('web')->user();

        // Roles are checked here and again in the LogViewer gate. This is the
        // one that matters: it refuses before a session exists, so an ordinary
        // admin never holds a cookie that would pass a later, looser check.
        if ($user === null
            || $user->status !== 'active'
            || ! in_array($user->role, config('log-viewer.allowed_roles', ['superadmin']), true)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'This account is not allowed to view logs.',
            ]);
        }

        // Only now, with the account accepted, hand it a fresh session id.
        $request->session()->regenerate();

        return redirect()->intended(route('log-viewer.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
