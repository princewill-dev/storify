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

        if ($user->business && $user->business->needsSubscription()) {
            $exemptRoutes = [
                'management.dashboard',
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
                'management.stores.create',
                'management.stores.store',
                'management.stores.index',
                'management.stores.show',
                'management.staff.index',
                'management.staff.create',
                'management.staff.store',
                'management.staff.show',
                'management.staff.edit',
                'management.staff.update',
                'management.staff.resend-invite',
                'management.staff.suspend',
                'management.staff.activate',
                'management.staff.destroy',
                'management.roles.index',
                'management.roles.create',
                'management.roles.store',
                'management.roles.edit',
                'management.roles.update',
                'management.roles.destroy',
                'management.warehouses.index',
                'management.warehouses.create',
                'management.warehouses.store',
                'management.warehouses.show',
                'management.warehouses.edit',
                'management.warehouses.update',
                'management.warehouses.destroy',
                'management.locations.index',
                'management.locations.create',
                'management.locations.store',
                'management.locations.show',
                'management.locations.edit',
                'management.locations.update',
                'management.locations.destroy',
                'management.sections.index',
                'management.sections.create',
                'management.sections.store',
                'management.sections.show',
                'management.sections.edit',
                'management.sections.update',
                'management.sections.destroy',
                'management.profile.index',
                'management.profile.password',
                'management.kyc.show',
                'management.kyc.submit',
                'management.payment-settings.index',
                'management.payment-settings.bank-accounts.store',
                'management.payment-settings.bank-accounts.update',
                'management.payment-settings.bank-accounts.destroy',
                'management.payment-settings.verify-bank',
                'management.auth.logout',
                'management.pos.sessions.index',
                'management.pos.sessions.show',
                'management.categories.index',
                'management.categories.create',
                'management.categories.store',
                'management.categories.edit',
                'management.categories.update',
                'management.categories.destroy',
                'management.services.index',
                'management.services.create',
                'management.services.store',
                'management.services.edit',
                'management.services.update',
                'management.services.destroy',
            ];

            if (!$request->routeIs($exemptRoutes)) {
                return redirect()->route('management.plans.index')
                    ->with('warning', 'Please select a plan to continue.');
            }
        }

        return $next($request);
    }
}
