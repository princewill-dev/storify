<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfOnboardingIncomplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return $next($request);
        }

        if ($user->isStaff()) {
            return $next($request);
        }

        $currentRoute = $request->route()->getName();

        if (!$user->is_verified) {
            $exemptRoutes = [
                'management.auth.verify-otp',
                'management.auth.verify-otp.store',
                'management.auth.verify-otp.resend',
                'management.auth.logout',
            ];

            if (!in_array($currentRoute, $exemptRoutes)) {
                return redirect()->route('management.auth.verify-otp')
                    ->with('warning', 'Please verify your email to continue.');
            }
        }

        if ($user->is_verified && !$user->business_id) {
            $exemptRoutes = [
                'management.setup',
                'management.setup.store',
                'management.plans.index',
                'management.plans.checkout',
                'management.plans.validate-coupon',
                'management.subscription.plan',
                'management.subscription.initialize',
                'management.subscription.callback',
                'management.subscription.check-early-pass',
                'management.subscription.activate-trial',
                'management.auth.logout',
                'management.auth.verify-otp',
                'management.auth.verify-otp.store',
                'management.auth.verify-otp.resend',
            ];

            if (!in_array($currentRoute, $exemptRoutes)) {
                return redirect()->route('management.setup')
                    ->with('info', 'Please complete your business setup to continue.');
            }
        }

        return $next($request);
    }
}
