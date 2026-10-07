<?php

namespace App\Services\Subscription;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionTrialSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The WS-07 plan workflows: recording a first plan selection (and starting the
 * one-time platform trial) and swapping the plan on an active subscription.
 *
 * Guards and response messages stay in PlanController; the queries come from
 * SubscriptionPlanRepository. This class owns the transaction boundaries and
 * the activity-log entries that follow them.
 */
final class PlanSelectionService
{
    public function __construct(private readonly SubscriptionTrialSettings $trialSettings) {}

    /**
     * Record the chosen plan on the user, starting the platform trial if this
     * is the business's first selection.
     *
     * A trial starts once: legacy re-ran `now()->addDays()` on every selection,
     * so a trialing user could extend the trial indefinitely by picking a
     * different plan. An existing (even expired) trial end date is never moved.
     */
    public function select(User $user, SubscriptionPlan $plan): PlanSelectionResult
    {
        $trial = $this->trialSettings->get();

        $startTrial = $trial['enabled'] && $user->trial_ends_at === null;
        $trialEndsAt = $startTrial ? now()->addDays($trial['days']) : $user->trial_ends_at;

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

        return new PlanSelectionResult($startTrial, $trialEndsAt, $trial['days']);
    }

    /**
     * Swap the plan on an active subscription: no proration and no charge
     * today — the new amount applies from the next renewal.
     *
     * Returns the re-read subscription with its plan loaded, ready for the
     * response.
     */
    public function change(User $user, Subscription $subscription, SubscriptionPlan $plan): Subscription
    {
        $oldPlanId = $subscription->subscription_plan_id;

        DB::transaction(function () use ($subscription, $plan) {
            $subscription->update(['subscription_plan_id' => $plan->id]);
        });

        Log::info('subscription.plan_changed', [
            'user_id' => $user->id,
            'old_plan_id' => $oldPlanId,
            'new_plan_id' => $plan->id,
        ]);

        return $subscription->fresh('subscriptionPlan');
    }
}
