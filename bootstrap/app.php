<?php

use App\Http\Middleware\EnsureCustomerHasBusiness;
use App\Http\Middleware\EnsurePlanFeature;
use App\Http\Middleware\EnsureTokenAudience;
use App\Http\Middleware\IsPlatformAdmin;
use App\Http\Middleware\SetPermissionsTeamId;
use App\Support\SpaUrls;
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
        ]);

        $middleware->alias([
            'permission' => PermissionMiddleware::class,
            'role' => RoleMiddleware::class,
            'team.context' => SetPermissionsTeamId::class,
            'platform.admin' => IsPlatformAdmin::class,
            'token.audience' => EnsureTokenAudience::class,
            // Storefront account routes: refuses a customer token whose row
            // belongs to no business, which is a row with no tenant to read.
            'customer.business' => EnsureCustomerHasBusiness::class,
            // Parameterised: `plan.feature:accounting`, `plan.feature:storefront`.
            // The entitlement key is the argument, so the next feature a plan can
            // grant is a word on a route group rather than a middleware class.
            'plan.feature' => EnsurePlanFeature::class,
        ]);

        // This app is API-only: an unauthenticated request gets a JSON 401 from
        // the auth middleware rather than a redirect to an HTML login page.
        // The exception is /logs, whose session guard genuinely needs the login
        // route named `login` — see routes/web.php. Guests are still not
        // redirected here; the auth middleware's own redirect handles them.
        //
        // Nothing uses the `guest` middleware any more, but if a package route
        // ever does, an already-signed-in visitor belongs in the console — not
        // on the log viewer, which may be disabled and then has no route at
        // all (resolving it here would throw during boot).
        $middleware->redirectUsersTo(fn () => SpaUrls::admin('/login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
