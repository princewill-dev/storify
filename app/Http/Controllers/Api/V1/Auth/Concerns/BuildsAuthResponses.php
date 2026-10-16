<?php

namespace App\Http\Controllers\Api\V1\Auth\Concerns;

use App\Mail\OtpMail;
use App\Models\Customer;
use App\Models\User;
use App\Services\OtpService;
use App\Support\Plans\Entitlements;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

trait BuildsAuthResponses
{
    protected function sendOtp(string $email, string $type, int $expiryMinutes = 10, ?int $businessId = null): void
    {
        $otp = OtpService::generate($email, $type, $expiryMinutes, $businessId);

        try {
            Mail::to($email)->queue(new OtpMail($otp->code, $expiryMinutes));
        } catch (\Throwable $e) {
            Log::error('api.otp.mail_failed', [
                'email' => $email,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function userPayload(User $user): array
    {
        setPermissionsTeamId($user->business_id);

        $user->loadMissing(['business', 'business.activeSubscription.subscriptionPlan']);

        return [
            'id' => $user->id,
            'account_code' => $user->account_code,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => $user->status,
            'is_verified' => (bool) $user->is_verified,
            'force_password_change' => (bool) $user->force_password_change,
            'theme_preference' => $user->theme_preference,
            // The console's theme, which is a different setting from the
            // terminal's above — a dark till and a light back office are a
            // perfectly ordinary pair, and one column cannot hold both.
            'theme' => $user->management_theme,
            'business_id' => $user->business_id,
            'business' => $user->business ? [
                'id' => $user->business->id,
                'name' => $user->business->name,
                'business_code' => $user->business->business_code,
                'status' => $user->business->status,
                'currency' => $user->business->currency,
                // Carried here rather than fetched separately: it is what the
                // SPA needs to order the `/welcome` cards and pre-tick the
                // store form, and both of those screens are reached straight
                // from an auth response. A list of strings, never null.
                'intents' => $user->business->onboarding_intents ?? [],
            ] : null,
            'subscription' => $this->subscriptionPayload($user),
            // What this business's plan entitles it to, and what it does not —
            // see Entitlements::payload(). Carried on the auth response because
            // that is the one payload the SPA caches for the whole session, and
            // the sidebar has to know before it renders whether each section is
            // open or locked. A second endpoint would be a second round trip on
            // every page load to answer a question already answered here.
            //
            // `owner` and `owner.selectedPlan` are read lazily by Entitlements
            // and only when there is no active subscription, which is why they
            // are not in the loadMissing above: a paying business never touches
            // them.
            'entitlements' => Entitlements::for($user->business)->payload(),
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'stores' => $this->storesPayload($user),
            'last_login_at' => $user->last_login_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function customerPayload(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'account_id' => $customer->account_id,
            'business_id' => $customer->business_id,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'name' => $customer->full_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'status' => $customer->status,
            'account_state' => $customer->accountState(),
            'email_verified' => $customer->email_verified_at !== null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function subscriptionPayload(User $user): ?array
    {
        $subscription = $user->business?->activeSubscription;

        if ($subscription) {
            return [
                'state' => 'active',
                'plan' => $subscription->subscriptionPlan?->name,
                'expires_at' => $subscription->expires_at?->toISOString(),
            ];
        }

        if ($user->trial_ends_at && $user->trial_ends_at->isFuture()) {
            return [
                'state' => 'trial',
                'trial_ends_at' => $user->trial_ends_at->toISOString(),
            ];
        }

        return ['state' => 'none'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function storesPayload(User $user): array
    {
        try {
            return $user->accessibleStores()
                ->where('status', '!=', 'deleted')
                ->get(['id', 'store_id', 'name', 'slug', 'pos_enabled', 'status'])
                ->map(fn ($store) => [
                    'id' => $store->id,
                    'store_id' => $store->store_id,
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'pos_enabled' => (bool) $store->pos_enabled,
                    'status' => $store->status,
                ])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The frontend's next onboarding step after authentication.
     *
     * The order is the flow, and each step is the first thing still missing:
     * a forced password change, then email verification, then a business, then
     * a plan, then the welcome interstitial. A staff member is short-circuited
     * to the dashboard on purpose — they are not being onboarded, they were
     * invited into somebody else's business — and that is also why `welcome`
     * lives on the business: a cashier must never be sent to their employer's
     * checklist.
     *
     * `welcome` sits *after* the subscription check and not before it, which
     * is the one ordering decision here worth stating. An owner who has paid
     * for nothing yet is sent to plans, not to a page congratulating them on
     * an account they cannot use. The interstitial is what follows access, not
     * a substitute for obtaining it.
     */
    protected function nextStep(User $user): string
    {
        if ($user->force_password_change) {
            return 'change_password';
        }

        if ($user->isStaff()) {
            return 'dashboard';
        }

        if (! $user->is_verified) {
            return 'verify_email';
        }

        if (! $user->business_id) {
            return 'setup';
        }

        $business = $user->business;

        if ($business && ! $business->hasActiveSubscription() && ! ($user->trial_ends_at && $user->trial_ends_at->isFuture())) {
            return 'plans';
        }

        // Reached only with access in hand. A business that has never been
        // welcomed gets the interstitial once; the moment it is seen, this
        // returns to being `dashboard` and never forces again. Existing
        // businesses are backfilled as seen by the migration that adds the
        // column, so they are not caught by this branch.
        if ($business && $business->welcome_seen_at === null) {
            return 'welcome';
        }

        return 'dashboard';
    }
}
