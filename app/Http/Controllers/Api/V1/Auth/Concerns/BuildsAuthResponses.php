<?php

namespace App\Http\Controllers\Api\V1\Auth\Concerns;

use App\Mail\OtpMail;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Impersonation;
use App\Models\User;
use App\Services\OtpService;
use App\Services\Payments\PaymentGatewayResolver;
use App\Support\Payments\SystemProcessor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

trait BuildsAuthResponses
{
    protected function sendOtp(string $email, string $type, int $expiryMinutes = 10): void
    {
        $otp = OtpService::generate($email, $type, $expiryMinutes);

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
            'business_id' => $user->business_id,
            'business' => $user->business ? [
                'id' => $user->business->id,
                'name' => $user->business->name,
                'business_code' => $user->business->business_code,
                'status' => $user->business->status,
                'currency' => $user->business->currency,
            ] : null,
            'subscription' => $this->subscriptionPayload($user),
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
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'name' => $customer->full_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'status' => $customer->status,
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

        if ($this->needsPayoutAccount($user, $business)) {
            return 'payout_account';
        }

        return 'dashboard';
    }

    /**
     * Whether this business still has to be asked where its sales are paid into.
     *
     * Answered for a platform that processes payments on a business's behalf,
     * where the destination is the one thing the business must supply and the
     * one thing nobody else can supply for it: Storify builds the provider's
     * subaccount out of this answer, and the money cannot reach a bank account
     * that was never named.
     *
     * Placed after `plans` in the order above on purpose. A business whose
     * subscription has lapsed is asked to pay first, and it can: subscription
     * billing runs on the platform's own provider credentials, not on the
     * business's, so being unable to take payments has never stopped a business
     * from paying Storify.
     *
     * Also exempt: anybody who could not submit the form. See the permission
     * check below for why that is a favour rather than a loophole.
     */
    private function needsPayoutAccount(User $user, ?Business $business): bool
    {
        if ($business === null) {
            return false;
        }

        // `code()`, not `enforced()`. A stored processor this build cannot run
        // is a state in which no submission can succeed, so it is an admin's to
        // fix and never a business's to be stuck on — the screen could only ever
        // fail, and the platform is dark either way.
        if (SystemProcessor::code() === null) {
            return false;
        }

        // An admin looking at a business through impersonation is not its owner:
        // it cannot open a bank account in the business's name, and it must not
        // accept a provider's terms on its behalf. So it is not intercepted —
        // which also means the admin can still see, from inside, that the
        // business is the state it is.
        if (Impersonation::activeForToken($user->currentAccessToken()) !== null) {
            return false;
        }

        // Only a member of the business who could actually submit this. The
        // screen is forced — every route goes through it and it cannot be
        // dismissed — so showing it to somebody the endpoint would refuse is not
        // a nudge, it is a trap with only the sign-out link as a way past it.
        // Skipping them costs nothing: money safety is the resolver's, not this
        // step's, and the account still has to be named before anything sells.
        setPermissionsTeamId($user->business_id);

        if (! $user->can('settings payment')) {
            return false;
        }

        if ($business->payout_bank_id === null) {
            return true;
        }

        // The bank account is named, so what is missing is the account at the
        // provider — which happens when an admin has since changed the processor
        // and a business that had registered has none at the new one. The same
        // answer the resolver gives about taking money, so the two cannot drift.
        return ! app(PaymentGatewayResolver::class)->enforcedProcessorReady((int) $business->id);
    }
}
