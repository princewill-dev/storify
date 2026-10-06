<?php

namespace App\Http\Controllers\Api\V1\Management\Subscription;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionTrialSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(private readonly SubscriptionTrialSettings $trialSettings) {}

    /**
     * The onboarding "Choose your plan" grid and the in-dashboard plan grid.
     */
    public function plans(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $plans = $this->activePlans();

        $monthly = $plans->where('interval', 'monthly')->values();
        $yearly = $plans->where('interval', 'yearly')->values();
        $other = $plans->whereNotIn('interval', ['monthly', 'yearly'])->values();
        $trial = $this->trialSettings->get();

        return $this->ok([
            'plans' => $plans->map(fn (SubscriptionPlan $plan) => $this->planPayload($plan))->all(),
            'monthly' => $monthly->map(fn (SubscriptionPlan $plan) => $this->planPayload($plan))->all(),
            'yearly' => $yearly->map(fn (SubscriptionPlan $plan) => $this->planPayload($plan))->all(),
            'other' => $other->map(fn (SubscriptionPlan $plan) => $this->planPayload($plan))->all(),
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
            'subscription' => $subscription ? $this->subscriptionPayload($subscription) : null,
            'trial' => [
                'enabled' => $trial['enabled'],
                'days' => $trial['days'],
                'active' => $user->isOnTrial(),
                'expired' => $user->trialHasExpired(),
                'ends_at' => $user->trial_ends_at?->toISOString(),
                'days_left' => $user->daysLeftOnTrial(),
            ],
            'selected_plan' => $user->selectedPlan ? $this->planPayload($user->selectedPlan) : null,
            'billing_history' => $this->billingHistory($user),
        ]);
    }

    /**
     * Choose a plan while unsubscribed: start the platform trial if enabled,
     * otherwise send the user on to payment.
     */
    public function select(Request $request): JsonResponse
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

        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
        ]);

        $plan = SubscriptionPlan::query()
            ->active()
            ->where('is_trial', false)
            ->find($data['plan_id']);

        if (! $plan) {
            return $this->error('Invalid plan selection.', 422);
        }

        $trial = $this->trialSettings->get();

        // A trial starts once. Legacy re-ran `now()->addDays()` on every
        // selection, so a trialing user could extend the trial indefinitely
        // by picking a different plan.
        $startTrial = $trial['enabled'] && $user->trial_ends_at === null;
        $trialEndsAt = $startTrial ? now()->addDays($trial['days']) : $user->trial_ends_at;
        $onTrial = $trialEndsAt !== null && $trialEndsAt->isFuture();

        DB::transaction(function () use ($user, $plan, $trialEndsAt) {
            $user->update([
                'selected_plan_id' => $plan->id,
                'trial_ends_at' => $trialEndsAt,
            ]);
        });

        Log::info('subscription.plan_selected', [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'trial_started' => $startTrial,
        ]);

        return $this->ok([
            'selected_plan' => $this->planPayload($plan),
            'trial_started' => $startTrial,
            'trial_ends_at' => $trialEndsAt?->toISOString(),
            'next' => $onTrial ? 'dashboard' : 'payment',
        ], $onTrial
            ? "Your {$trial['days']}-day free trial has started!"
            : 'Please complete payment to activate your subscription.');
    }

    /**
     * Swap the plan on an active subscription. No proration, no charge today —
     * the new amount applies from the next renewal.
     */
    public function change(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $subscription = $user->business?->activeSubscription()->first();

        if (! $subscription) {
            return $this->error('No active subscription found.', 422);
        }

        $data = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
        ]);

        $plan = SubscriptionPlan::query()
            ->active()
            ->where('is_trial', false)
            ->find($data['plan_id']);

        if (! $plan || (int) $plan->id === (int) $subscription->subscription_plan_id) {
            return $this->error('Invalid plan selection.', 422);
        }

        $oldPlanId = $subscription->subscription_plan_id;

        DB::transaction(function () use ($subscription, $plan) {
            $subscription->update(['subscription_plan_id' => $plan->id]);
        });

        Log::info('subscription.plan_changed', [
            'user_id' => $user->id,
            'old_plan_id' => $oldPlanId,
            'new_plan_id' => $plan->id,
        ]);

        return $this->ok([
            'subscription' => $this->subscriptionPayload($subscription->fresh('subscriptionPlan')),
        ], "Your plan has been changed to {$plan->name}. The new billing amount will apply on your next renewal.");
    }

    /**
     * @return Collection<int, SubscriptionPlan>
     */
    private function activePlans()
    {
        return SubscriptionPlan::query()
            ->active()
            ->where('is_trial', false)
            ->orderBy('sort_order')
            ->orderBy('amount')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function billingHistory(User $user): array
    {
        if (! $user->business_id) {
            return [];
        }

        // Legacy scoped this to the *active* subscription, so a business's
        // payment history vanished the moment its subscription expired. Read
        // every subscription payment for the business instead.
        return Payment::query()
            ->forBusiness($user->business_id)
            ->whereNotNull('subscription_id')
            ->latest()
            ->take(20)
            ->get()
            ->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'amount' => (float) $payment->amount,
                'amount_kobo' => $this->toKobo($payment->amount),
                'currency' => $payment->currency,
                'status' => $payment->status,
                'payment_type' => $payment->payment_type,
                'plan_name' => $payment->metadata['plan_name'] ?? null,
                'paid_at' => $payment->paid_at?->toISOString(),
                'created_at' => $payment->created_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionPayload(Subscription $subscription): array
    {
        $plan = $subscription->subscriptionPlan;

        return [
            'id' => $subscription->id,
            'subscription_code' => $subscription->subscription_code,
            'status' => $subscription->status,
            'is_active' => $subscription->isActive(),
            'starts_at' => $subscription->starts_at?->toISOString(),
            'expires_at' => $subscription->expires_at?->toISOString(),
            'plan' => $plan ? $this->planPayload($plan) : null,
            'next_amount' => $plan ? (float) $plan->amount : null,
            'next_amount_kobo' => $plan ? $this->toKobo($plan->amount) : null,
            'billing_cycle' => $plan ? $this->intervalLabel($plan) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function planPayload(SubscriptionPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'name' => $plan->name,
            'description' => $plan->description,
            'amount' => (float) $plan->amount,
            'amount_kobo' => $this->toKobo($plan->amount),
            'currency' => $plan->currency,
            'interval' => $plan->interval,
            'interval_count' => (int) $plan->interval_count,
            'interval_label' => $this->intervalLabel($plan),
            'features' => $plan->features ?? [],
            'is_default' => (bool) $plan->is_default,
            'trial_days' => $plan->trial_days !== null ? (int) $plan->trial_days : null,
            'sort_order' => (int) $plan->sort_order,
        ];
    }

    private function intervalLabel(SubscriptionPlan $plan): string
    {
        $count = (int) $plan->interval_count;

        return '/'.$plan->interval.($count > 1 ? 's' : '');
    }

    /**
     * Cheapest monthly (×12) vs cheapest yearly, as an integer percentage.
     * Computed in kobo so no float arithmetic touches money.
     */
    private function yearlySavingsPercent($monthlyPlans, $yearlyPlans): ?int
    {
        if ($monthlyPlans->isEmpty() || $yearlyPlans->isEmpty()) {
            return null;
        }

        $annualMonthlyCost = $this->toKobo($monthlyPlans->min('amount')) * 12;
        $cheapestYearly = $this->toKobo($yearlyPlans->min('amount'));

        if ($annualMonthlyCost <= 0) {
            return null;
        }

        $saving = $annualMonthlyCost - $cheapestYearly;

        // Integer-only rounding: (saving * 100) / annual, rounded to nearest.
        return intdiv($saving * 100 + intdiv($annualMonthlyCost, 2), $annualMonthlyCost);
    }

    /**
     * The plan/payment tables hold decimal naira; the API also speaks kobo so
     * callers never do float arithmetic on money. String parsing keeps it exact.
     */
    private function toKobo(mixed $amount): int
    {
        $value = (string) ($amount ?? '0');
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        $kobo = ((int) $whole) * 100 + (int) $fraction;

        return $negative ? -$kobo : $kobo;
    }
}
