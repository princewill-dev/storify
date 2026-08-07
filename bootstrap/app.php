<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// --- ADD THIS BLOCK ---
$storageDirs = [
    __DIR__.'/../storage/app/public',
    __DIR__.'/../storage/framework/cache/data',
    __DIR__.'/../storage/framework/sessions',
    __DIR__.'/../storage/framework/testing',
    __DIR__.'/../storage/framework/views',
    __DIR__.'/../storage/logs',
];

foreach ($storageDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}
// ----------------------

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
            'management.subscription' => \App\Http\Middleware\CheckSubscription::class,
            'management.onboarding' => \App\Http\Middleware\RedirectIfOnboardingIncomplete::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'team.context' => \App\Http\Middleware\SetPermissionsTeamId::class,
        ]);
        
        // Configure authentication redirects for customer guard
        $middleware->redirectGuestsTo(function ($request) {
            if ($request->is('pos') || $request->is('pos/*')) {
                return route('pos.login');
            }

            if ($request->is('management') || $request->is('management/*')
                || $request->is('staff') || $request->is('staff/*')) {
                return route('management.auth.login');
            }

            if ($request->is('*/checkout') || $request->is('*/checkout/*')) {
                // Extract store slug from URL
                $segments = $request->segments();
                $storeSlug = $segments[0] ?? null;
                
                // Store checkout redirect info in session
                session([
                    'checkout_redirect' => true,
                    'checkout_store_slug' => $storeSlug
                ]);
            }
            
            return route('account.login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
