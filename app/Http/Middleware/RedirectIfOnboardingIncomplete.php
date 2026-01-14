<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfOnboardingIncomplete
{
    /**
     * Handle an incoming request.
     * Redirect vendors to their next onboarding step if incomplete
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var \App\Models\Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor) {
            return $next($request);
        }

        // Skip redirect for onboarding routes themselves
        $onboardingRoutes = [
            'vendor.store.create',
            'vendor.store.submit',
            'vendor.store.success',
            'vendor.payment-methods.form',
            'vendor.payment-methods.bank',
            'vendor.payment-methods.paystack',
            'vendor.payment-methods.skip',
            'vendor.delivery-routes.form',
            'vendor.delivery-routes.save',
            'vendor.delivery-routes.skip',
            'vendor.subscription.plan',
            'vendor.subscription.initiate',
            'vendor.subscription.callback',
            'vendor.early-pass.check',
        ];

        $currentRoute = $request->route()->getName();

        // Don't redirect if already on an onboarding route
        if (in_array($currentRoute, $onboardingRoutes)) {
            return $next($request);
        }

        // Check if onboarding is complete
        if (!$vendor->hasCompletedOnboarding()) {
            $nextStep = $vendor->getNextOnboardingStep();
            
            \Log::info('[Onboarding Redirect] Redirecting incomplete vendor', [
                'vendor_id' => $vendor->id,
                'current_route' => $currentRoute,
                'next_step' => $nextStep,
            ]);

            return redirect($nextStep);
        }

        return $next($request);
    }
}
