<?php

namespace App\Http\Controllers\Api\V1\Management\Subscription;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Jobs\ProcessTrialExpirations;
use App\Models\Store;
use App\Models\User;
use App\Services\SubscriptionGate;
use App\Services\SubscriptionTrialSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * WS-09 — the subscription gate state and the dashboard banner payload.
 *
 * The roadmap folded this into GET /management/dashboard; it lives on its own
 * endpoint because the dashboard controller is owned by the widgets
 * workstream. The SPA mounts `<SubscriptionBanner>` on the dashboard (and any
 * other screen that needs the notice) and reads this payload.
 */
class GateController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly SubscriptionGate $gate,
        private readonly SubscriptionTrialSettings $trialSettings,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $routeName = $request->route()?->getName();

        $state = $this->state($user);

        // "blocked" answers "would this user be refused away from the exempt
        // list?", so it stays meaningful even though this endpoint itself is
        // exempt (the SPA needs to know whether to redirect the user).
        $blocked = ! $this->gate->allows($user, 'api.management.__probe');
        $refusal = $blocked ? $this->gate->refusal($user) : null;
        $trial = $this->trialSettings->get();

        $subscription = $user->business?->activeSubscription()->first();

        return $this->ok([
            'is_owner' => $user->isBusinessOwner(),
            'as_of' => Carbon::now()->toISOString(),
            'gate' => [
                'state' => $state,
                'blocked' => $blocked,
                'current_route_exempt' => $this->gate->isExempt($routeName),
                'code' => $refusal['code'] ?? null,
                'message' => $refusal['message'] ?? null,
                'redirect' => $refusal['redirect'] ?? null,
            ],
            'subscription' => $subscription ? [
                'id' => $subscription->id,
                'subscription_code' => $subscription->subscription_code,
                'status' => $subscription->status,
                'plan_name' => $subscription->subscriptionPlan?->name,
                'starts_at' => $subscription->starts_at?->toISOString(),
                'expires_at' => $subscription->expires_at?->toISOString(),
                'renews_in_days' => $subscription->expires_at
                    ? max(0, (int) Carbon::now()->diffInDays($subscription->expires_at, false))
                    : null,
            ] : null,
            'trial' => [
                'enabled' => $trial['enabled'],
                'days' => $trial['days'],
                'active' => $user->isOnTrial(),
                'expired' => $user->trialHasExpired(),
                'ends_at' => $user->trial_ends_at?->toISOString(),
                'days_left' => $user->daysLeftOnTrial(),
            ],
            'selected_plan' => $user->selectedPlan ? [
                'id' => $user->selectedPlan->id,
                'name' => $user->selectedPlan->name,
                'amount' => (float) $user->selectedPlan->amount,
                'currency' => $user->selectedPlan->currency,
                'interval' => $user->selectedPlan->interval,
            ] : null,
            'stores' => $this->storeCounts($user),
            'lifecycle' => [
                'reminder_window_days' => ProcessTrialExpirations::REMINDER_WINDOW_DAYS,
                'pause_after_expiry_days' => ProcessTrialExpirations::PAUSE_AFTER_EXPIRY_DAYS,
                'stores_pause_at' => $user->trial_ends_at
                    ? $user->trial_ends_at->copy()->addDays(ProcessTrialExpirations::PAUSE_AFTER_EXPIRY_DAYS)->toISOString()
                    : null,
                'store_pause_pending' => $user->trialHasExpired()
                    && $user->trial_ends_at
                    && $user->trial_ends_at->copy()->addDays(ProcessTrialExpirations::PAUSE_AFTER_EXPIRY_DAYS)->isFuture(),
            ],
            'banner' => $this->banner($user, $state),
            'links' => [
                'plans' => '/plans',
                'subscription' => '/subscription',
                'payment' => '/subscription/payment',
                'gate' => '/subscription/gate',
            ],
        ]);
    }

    /**
     * One label for the whole access state, so the SPA never re-derives it.
     */
    private function state(User $user): string
    {
        if ($user->isStaff()) {
            return 'staff';
        }

        if (! $user->is_verified) {
            return 'unverified';
        }

        if ($user->business_id === null) {
            return 'setup';
        }

        if ($user->business?->hasActiveSubscription()) {
            return 'active';
        }

        if ($user->isOnTrial()) {
            return ($user->daysLeftOnTrial() ?? 0) <= 2 ? 'trial_ending' : 'trial';
        }

        if ($user->trialHasExpired()) {
            return 'trial_expired';
        }

        if ($user->selected_plan_id) {
            return 'plan_selected_unpaid';
        }

        return 'no_plan';
    }

    /**
     * The four legacy dashboard banners, mutually exclusive, with the CTA
     * targets mapped onto the SPA's routes. Copy follows
     * `resources/views/management/dashboard.blade.php` (lines 39–74) except
     * for the expired-trial body: legacy said the stores were paused even
     * during the three-day grace window before the job actually pauses them.
     *
     * @return array<string, mixed>|null
     */
    private function banner(User $user, string $state): ?array
    {
        return match ($state) {
            'trial_ending' => [
                'variant' => 'trial_ending',
                'tone' => 'blue',
                'title' => $this->trialEndingTitle($user),
                'body' => 'Subscribe now to keep your stores running smoothly.',
                'cta_label' => 'Upgrade Now',
                'cta_to' => '/subscription/payment',
            ],
            'trial_expired' => [
                'variant' => 'trial_expired',
                'tone' => 'red',
                'title' => 'Your free trial has expired',
                'body' => $this->expiredTrialBody($user),
                'cta_label' => 'Subscribe Now',
                'cta_to' => '/subscription/payment',
            ],
            'plan_selected_unpaid' => [
                'variant' => 'plan_selected_unpaid',
                'tone' => 'amber',
                'title' => 'Complete your subscription',
                'body' => 'You chose the '.($user->selectedPlan?->name ?? 'selected').' plan. Pay now to activate your stores.',
                'cta_label' => 'Pay Now',
                'cta_to' => '/subscription/payment',
            ],
            'no_plan' => [
                'variant' => 'no_plan',
                'tone' => 'amber',
                'title' => 'Complete your subscription',
                'body' => 'Select a plan to activate your stores and start selling.',
                // Legacy pointed this one at the in-dashboard subscription page
                // (`management.subscription.plan`), not the onboarding grid.
                'cta_label' => 'Choose Plan',
                'cta_to' => '/subscription',
            ],
            default => null,
        };
    }

    private function trialEndingTitle(User $user): string
    {
        $daysLeft = $user->daysLeftOnTrial();

        // Legacy rendered "ends in 0 days" during the final 24 hours; say
        // "today" instead without changing when the banner appears.
        if ($daysLeft === null || $daysLeft <= 0) {
            return 'Your free trial ends today';
        }

        return 'Your free trial ends in '.$daysLeft.' '.($daysLeft === 1 ? 'day' : 'days');
    }

    private function expiredTrialBody(User $user): string
    {
        $pausesAt = $user->trial_ends_at?->copy()->addDays(ProcessTrialExpirations::PAUSE_AFTER_EXPIRY_DAYS);

        if ($pausesAt === null || $pausesAt->isPast()) {
            return 'Your stores are paused. Subscribe to reactivate them.';
        }

        return 'Your stores pause on '.$pausesAt->toFormattedDateString().'. Subscribe to keep them online.';
    }

    /**
     * @return array<string, int>
     */
    private function storeCounts(User $user): array
    {
        if ($user->business_id === null) {
            return ['total' => 0, 'active' => 0, 'pending' => 0, 'suspended' => 0];
        }

        $counts = Store::query()
            ->where('business_id', $user->business_id)
            ->where('status', '!=', Store::STATUS_DELETED)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $active = (int) ($counts[Store::STATUS_ACTIVE] ?? 0);
        $pending = (int) ($counts[Store::STATUS_PENDING] ?? 0);
        $suspended = (int) ($counts[Store::STATUS_SUSPENDED] ?? 0);

        return [
            'total' => $active + $pending + $suspended,
            'active' => $active,
            'pending' => $pending,
            'suspended' => $suspended,
        ];
    }
}
