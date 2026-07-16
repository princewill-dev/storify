<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        if ($user->isStaff()) {
            return $next($request);
        }

        if (!$user->is_verified) {
            return redirect()->route('management.auth.verify-otp')
                ->with('warning', 'Please verify your email to continue.');
        }

        if ($user->isOnTrial()) {
            return $next($request);
        }

        if ($user->business && $user->business->needsSubscription()) {
            $exemptRoutes = [
                'management.dashboard',
                'management.setup',
                'management.setup.store',
                'management.plans.index',
                'management.plans.checkout',
                'management.plans.validate-coupon',
                'management.subscription.plan',
                'management.subscription.select-plan',
                'management.subscription.change-plan',
                'management.subscription.payment',
                'management.subscription.process-payment',
                'management.subscription.callback',
                'management.subscription.check-early-pass',
                'management.profile.index',
                'management.profile.password',
                'management.kyc.show',
                'management.kyc.submit',
                'management.auth.logout',
            ];

            if (!$request->routeIs($exemptRoutes)) {
                return redirect()->route('management.plans.index')
                    ->with('warning', 'Please select a plan to continue.');
            }
        }

        return $next($request);
    }
}
