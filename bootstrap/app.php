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
            'webhooks/paystack',
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
        // The previous closure resolved legacy Blade login route names, which
        // no longer exist.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
