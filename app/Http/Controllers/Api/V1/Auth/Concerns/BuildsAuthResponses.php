<?php

namespace App\Http\Controllers\Api\V1\Auth\Concerns;

use App\Mail\OtpMail;
use App\Models\Customer;
use App\Models\User;
use App\Services\OtpService;
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

        return 'dashboard';
    }
}
