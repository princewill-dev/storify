<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The API half of legacy's forced-password-change gate.
 *
 * The legacy web middleware (RedirectIfOnboardingIncomplete) redirected a user
 * whose `force_password_change` flag was set to the change-password screen and
 * blocked everything else. The SPA honours the `next: change_password` signal,
 * but that is only a hint — without this, a user in that state could still call
 * any management endpoint directly, which is what the flag exists to prevent.
 *
 * Answers 403 with the same {message, code, redirect} shape as
 * EnsureManagementSubscription, so the SPA handles both refusals the same way.
 *
 * Exemptions match legacy exactly: only the auth group, which carries
 * change-password, logout and `me` (the SPA needs `me` to learn it is gated).
 */
class EnsureManagementOnboarding
{
    /**
     * Route-name prefixes that stay reachable while a change is forced.
     */
    private const EXEMPT_PREFIXES = ['auth.'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->isStaff()) {
            return $next($request);
        }

        if (! $user->force_password_change) {
            return $next($request);
        }

        try {
            $routeName = $request->route()?->getName();
        } catch (Throwable) {
            $routeName = null;
        }

        $name = $routeName === null
            ? ''
            : (Str::startsWith($routeName, 'api.management.') ? Str::after($routeName, 'api.management.') : $routeName);

        if ($name !== '' && Str::startsWith($name, self::EXEMPT_PREFIXES)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Please set a new password to continue.',
            'code' => 'change_password',
            'redirect' => '/change-password',
        ], Response::HTTP_FORBIDDEN);
    }
}
