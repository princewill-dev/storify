<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->replace(
            \Illuminate\Http\Middleware\TrustProxies::class,
            \Monicahq\Cloudflare\Http\Middleware\TrustProxies::class
        );
        
        $middleware->alias([
            'vendor.subscription' => \App\Http\Middleware\CheckVendorSubscription::class,
        ]);
        
        // Configure authentication redirects for customer guard
        $middleware->redirectGuestsTo(function ($request) {
            if ($request->is('vendor') || $request->is('vendor/*')) {
                return route('vendor.auth.login');
            }

            // Check if this is a checkout route
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
