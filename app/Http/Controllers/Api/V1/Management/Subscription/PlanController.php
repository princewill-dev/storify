<?php

namespace App\Http\Controllers\Api\V1\Management\Subscription;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\Subscription\PlanSelectionRequest;
use App\Http\Resources\Subscription\ActiveSubscriptionResource;
use App\Http\Resources\Subscription\BillingHistoryPaymentResource;
use App\Http\Resources\Subscription\SubscriptionPlanResource;
use App\Repositories\Subscription\SubscriptionPlanRepository;
use App\Services\Subscription\PlanSelectionService;
use App\Services\SubscriptionTrialSettings;
use App\Support\Money\Naira;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PlanController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly SubscriptionPlanRepository $plans,
        private readonly PlanSelectionService $planSelection,
        private readonly SubscriptionTrialSettings $trialSettings,
    ) {}

    /**
     * The onboarding "Choose your plan" grid and the in-dashboard plan grid.
     */
    public function plans(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $plans = $this->plans->activeCatalogue();

        $monthly = $plans->where('interval', 'monthly')->values();
        $yearly = $plans->where('interval', 'yearly')->values();
        $other = $plans->whereNotIn('interval', ['monthly', 'yearly'])->values();
        $trial = $this->trialSettings->get();

        return $this->ok([
            'plans' => SubscriptionPlanResource::rows($plans),
            'monthly' => SubscriptionPlanResource::rows($monthly),
            'yearly' => SubscriptionPlanResource::rows($yearly),
            'other' => SubscriptionPlanResource::rows($other),
            'yearly_savings_percent' => $this->yearlySavingsPercent($monthly, $yearly),
            'trial_enabled' => $trial['enabled'],
            'trial_days' => $trial['days'],
            'has_active_subscription' => (bool) $user->business?->hasActiveSubscription(),
            // Improve on legacy: the plan grid hid every CTA once a plan was
            // selected, so trialing users saw buttonless cards.
            'selected_plan_id' => $user->selected_plan_id,
        ]);
    }

    /**
     * The in-dashboard subscription page: current plan, trial state and billing history.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $subscription = $user->business?->activeSubscription()->first();
        $trial = $this->trialSettings->get();

        return $this->ok([
            'subscription' => $subscription ? ActiveSubscriptionResource::make($subscription)->resolve() : null,
            'trial' => [
                'enabled' => $trial['enabled'],
                'days' => $trial['days'],
                'active' => $user->isOnTrial(),
                'expired' => $user->trialHasExpired(),
                'ends_at' => $user->trial_ends_at?->toISOString(),
                'days_left' => $user->daysLeftOnTrial(),
            ],
            'selected_plan' => $user->selectedPlan ? SubscriptionPlanResource::make($user->selectedPlan)->resolve() : null,
            'billing_history' => BillingHistoryPaymentResource::rows(
                $this->plans->recentBillingHistory($user->business_id),
            ),
        ]);
    }

    /**
     * Choose a plan while unsubscribed: start the platform trial if enabled,
     * otherwise send the user on to payment.
     */
    public function select(PlanSelectionRequest $request): JsonResponse
    {
        $user = $this->user($request);

        if ($user->business?->hasActiveSubscription()) {
            return $this->error('You already have an active subscription.', 422);
        }

        // Legacy force-set `is_verified = true` here, which let an unverified
        // account skip email verification by selecting a plan. The plan flow
        // now refuses instead — verification comes first (auth `next` gate).
        if (! $user->is_verified) {
            return $this->error('Please verify your email address before choosing a plan.', 403);
        }

        $data = $request->validated();

        $plan = $this->plans->findSelectable((int) $data['plan_id']);

        if (! $plan) {
            return $this->error('Invalid plan selection.', 422);
        }

        $selection = $this->planSelection->select($user, $plan);

        $onTrial = $selection->trialEndsAt !== null && $selection->trialEndsAt->isFuture();

        return $this->ok([
            'selected_plan' => SubscriptionPlanResource::make($plan)->resolve(),
            'trial_started' => $selection->trialStarted,
            'trial_ends_at' => $selection->trialEndsAt?->toISOString(),
            'next' => $onTrial ? 'dashboard' : 'payment',
        ], $onTrial
            ? "Your {$selection->trialDays}-day free trial has started!"
            : 'Please complete payment to activate your subscription.');
    }

    /**
     * Swap the plan on an active subscription. No proration, no charge today —
     * the new amount applies from the next renewal.
     */
    public function change(PlanSelectionRequest $request): JsonResponse
    {
        $user = $this->user($request);

        // The subscription is resolved from the caller's own business, never
        // from an id in the request — another business's subscription is
        // unreachable.
        $subscription = $user->business?->activeSubscription()->first();

        if (! $subscription) {
            return $this->error('No active subscription found.', 422);
        }

        $data = $request->validated();

        $plan = $this->plans->findSelectable((int) $data['plan_id']);

        if (! $plan || (int) $plan->id === (int) $subscription->subscription_plan_id) {
            return $this->error('Invalid plan selection.', 422);
        }

        $subscription = $this->planSelection->change($user, $subscription, $plan);

        return $this->ok([
            'subscription' => ActiveSubscriptionResource::make($subscription)->resolve(),
        ], "Your plan has been changed to {$plan->name}. The new billing amount will apply on your next renewal.");
    }

    /**
     * Cheapest monthly (×12) vs cheapest yearly, as an integer percentage.
     * Computed in kobo so no float arithmetic touches money.
     */
    private function yearlySavingsPercent(Collection $monthlyPlans, Collection $yearlyPlans): ?int
    {
        if ($monthlyPlans->isEmpty() || $yearlyPlans->isEmpty()) {
            return null;
        }

        $annualMonthlyCost = Naira::koboFromLenient($monthlyPlans->min('amount')) * 12;
        $cheapestYearly = Naira::koboFromLenient($yearlyPlans->min('amount'));

        if ($annualMonthlyCost <= 0) {
            return null;
        }

        $saving = $annualMonthlyCost - $cheapestYearly;

        // Integer-only rounding: (saving * 100) / annual, rounded to nearest.
        return intdiv($saving * 100 + intdiv($annualMonthlyCost, 2), $annualMonthlyCost);
    }
}
