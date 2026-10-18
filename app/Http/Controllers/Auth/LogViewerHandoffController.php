<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auth\LogViewerAccess;
use App\Support\SpaUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The session bridge between the admin console and the log viewer.
 *
 * Every other surface of this API authenticates with a Sanctum token, but the
 * log viewer is a top-level browser navigation and cannot send one. It reads
 * the `web` guard instead, and this controller is how that guard gets a user:
 * the console mints a short-lived signed URL from its authenticated API call,
 * the browser opens it, and this endpoint exchanges it for a session cookie
 * before redirecting to /logs.
 *
 * The user id arrives as a signed query parameter, not a route-model binding.
 * `User::getRouteKeyName()` is `account_code`, and route bindings resolve
 * before the `signed` middleware runs, so a bound parameter would either look
 * up the wrong column or 404 before the signature was ever checked.
 */
class LogViewerHandoffController extends Controller
{
    /**
     * Keep the `login` route name alive, and send it somewhere useful.
     *
     * `Illuminate\Auth\Middleware\Authenticate` resolves this route by name
     * when a guest asks for /logs; deleting the name would turn that redirect
     * into a 500. The console's own login screen is the right destination now
     * that the Blade form is gone.
     */
    public function login(): RedirectResponse
    {
        return redirect()->to(SpaUrls::admin('/login'));
    }

    public function __invoke(Request $request): RedirectResponse
    {
        // Reading the id here is safe: the `signed` middleware has already
        // proven the server produced this exact URL.
        $user = User::find($request->integer('user'));

        // Checked again on purpose. A link minted while the account was an
        // active superadmin must not still work after a demotion or suspension.
        if ($user === null || ! LogViewerAccess::allows($user)) {
            abort(403, 'You do not have access to the server logs.');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('log-viewer.index')
            ->header('Cache-Control', 'no-store, private');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to(SpaUrls::admin('/login'));
    }
}
