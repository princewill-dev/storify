<?php

use App\Http\Middleware\EnsureTokenAudience;
use App\Http\Middleware\IsPlatformAdmin;
use App\Http\Middleware\SetPermissionsTeamId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            // Provider webhooks: they carry a signed body, not a session, so a
            // CSRF token is both impossible and meaningless. The wildcard
            // covers every provider's generic endpoint — without it each new
            // gateway would 419 until someone remembered to add its path.
            'webhooks/*',
            'payment/paystack/webhook',
        ]);

        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'team.context' => SetPermissionsTeamId::class,
            'platform.admin' => IsPlatformAdmin::class,
            'token.audience' => EnsureTokenAudience::class,
        ]);

        // This app is API-only: an unauthenticated request gets a JSON 401 from
        // the auth middleware rather than a redirect to an HTML login page.
        // The exception is /logs, whose session guard genuinely needs the login
        // route named `login` — see routes/web.php. Guests are still not
        // redirected here; the auth middleware's own redirect handles them.
        //
        // Signed-in visitors to /login go to the log viewer rather than to
        // whatever defaultRedirectUri() happens to find first (it looks for
        // `dashboard`, then `home`, then '/', none of which mean anything here).
        $middleware->redirectUsersTo(fn () => route('log-viewer.index'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
