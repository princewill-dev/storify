<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckVendorSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $vendor = $request->user('vendor');

        if (!$vendor) {
            Log::warning('vendor.subscription.middleware.no_vendor', [
                'path' => $request->path(),
            ]);
            return redirect()->route('vendor.auth.login');
        }

        if (!$vendor->is_verified) {
            Log::info('vendor.subscription.middleware.unverified', [
                'vendor_id' => $vendor->id,
            ]);
            return redirect()->route('vendor.auth.verify-otp', ['vendor' => $vendor])
                ->with('warning', 'Please verify your email to continue.');
        }

        if (!$vendor->stores()->exists()) {
            Log::info('vendor.subscription.middleware.no_store', [
                'vendor_id' => $vendor->id,
            ]);
            return redirect()->route('vendor.store.create', ['vendor' => $vendor]);
        }

        if ($vendor->needsSubscription()) {
            $exemptRoutes = [
                'vendor.subscription.plan',
                'vendor.subscription.initialize',
                'vendor.subscription.callback',
                'vendor.auth.logout',
            ];

            if (!$request->routeIs($exemptRoutes)) {
                Log::info('vendor.subscription.middleware.needs_subscription', [
                    'vendor_id' => $vendor->id,
                    'attempted_route' => $request->route()?->getName(),
                ]);
                return redirect()->route('vendor.subscription.plan', ['vendor' => $vendor])
                    ->with('warning', 'Please complete your subscription to access this feature.');
            }
        }

        return $next($request);
    }
}
