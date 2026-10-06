<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * WS-09 — the server-side subscription gate.
 *
 * Legacy's `CheckSubscription` middleware redirected web routes to
 * `/management/plans` and kept an 18-name exempt list. This is the API
 * equivalent: same semantics (staff and trials pass, owners without an active
 * subscription are refused outside the exempt routes) but reported as JSON so
 * an SPA can redirect instead of a browser following a 302.
 *
 * Two verify-identified holes in the legacy exempt list are fixed here, not
 * ported:
 *  - `plans.remove-coupon` was not exempt, so a gated owner's coupon-removal
 *    POST was bounced and the session coupon could never be cleared;
 *  - the payment routes were onboarding-gated (`select-plan`,
 *    `subscription.payment`, `subscription.process-payment`), so the
 *    "plans → pay" order only worked once a business existed.
 * The whole `plans.*` / `subscription.*` family is therefore exempt: those
 * routes are how a business without a subscription gets one.
 */
class SubscriptionGate
{
    /**
     * Route names (with the `api.management.` prefix stripped) reachable
     * without an active subscription.
     *
     * @var list<string>
     */
    public const EXEMPT_ROUTES = [
        'dashboard',
        'setup',
        'auth.me',
        'auth.profile',
        'auth.change-password',
        'auth.logout',
        'auth.logout-all',
        'auth.stop-impersonation',
        'profile.index',
        'profile.password',
        'profile.update',
        'kyc.index',
        'kyc.show',
        'kyc.store',
        'kyc.submit',
        'plans.check-early-pass',
    ];

    /**
     * Whole namespaces that stay reachable. Route modules register their exact
     * names as they land, so matching the prefix keeps a gated owner able to
     * browse plans, redeem a coupon or finish a payment on every screen in
     * that family.
     *
     * @var list<string>
     */
    public const EXEMPT_PREFIXES = ['auth.', 'profile.', 'kyc.', 'plans.', 'subscription.'];

    /**
     * The message legacy flashed when it bounced an owner to the plans page.
     */
    public const REFUSAL_MESSAGE = 'Please select a plan to continue.';

    public const VERIFICATION_MESSAGE = 'Please verify your email to continue.';

    public function isExempt(?string $routeName): bool
    {
        if ($routeName === null) {
            return false;
        }

        $name = Str::startsWith($routeName, 'api.management.')
            ? Str::after($routeName, 'api.management.')
            : $routeName;

        if (in_array($name, self::EXEMPT_ROUTES, true)) {
            return true;
        }

        return Str::startsWith($name, self::EXEMPT_PREFIXES);
    }

    /**
     * Whether this request may pass the gate.
     */
    public function allows(User $user, ?string $routeName): bool
    {
        // Staff are exempt in legacy: the owner carries the commercial
        // responsibility for the business, not the cashier.
        if ($user->isStaff()) {
            return true;
        }

        if ($this->isExempt($routeName)) {
            return true;
        }

        if (! $user->is_verified) {
            return false;
        }

        // A user without a business is still being onboarded; the setup flow
        // owns that redirect (legacy checked the same way).
        if ($user->business_id === null) {
            return true;
        }

        if ($user->business?->hasActiveSubscription()) {
            return true;
        }

        return $user->trial_ends_at !== null && $user->trial_ends_at->isFuture();
    }

    /**
     * The refusal payload for a blocked request: what the SPA should show and
     * where it should send the user.
     *
     * @return array{code: string, message: string, redirect: string}
     */
    public function refusal(User $user): array
    {
        if (! $user->is_verified) {
            return [
                'code' => 'verify_email',
                'message' => self::VERIFICATION_MESSAGE,
                'redirect' => '/verify-otp',
            ];
        }

        return [
            'code' => 'subscription_required',
            'message' => self::REFUSAL_MESSAGE,
            'redirect' => '/plans',
        ];
    }
}
