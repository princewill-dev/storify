<?php

namespace App\Services\Subscription;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Repositories\Subscription\SubscriptionPaymentRepository;
use App\Services\ActivityRecorder;
use App\Services\SubscriptionTrialSettings;
use App\Support\Money\Naira;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The WS-07 plan workflows: recording a first plan selection (and starting the
 * one-time platform trial) and changing the plan on an active subscription.
 *
 * Guards and response messages stay in PlanController; the queries come from
 * SubscriptionPlanRepository. This class owns the transaction boundaries and
 * the activity-log entries that follow them.
 *
 * ## A plan change costs money now, and that is the whole of the change
 *
 * `change()` used to be one `UPDATE`: any business could move to the most
 * expensive plan mid-period for free, and the time it had already paid for was
 * silently discarded. What it does instead is decided by the two plans' `tier`
 * and their prices, in this order:
 *
 *  - **the same rank and the same price** — refused. There is nothing to grant.
 *  - **a downgrade** — booked, not applied. It takes effect when the paid period
 *    ends, because the current period was paid for at the higher plan's price
 *    and taking it away early would be taking back time somebody bought.
 *  - **an upgrade with nothing to pay** — applied at once (the difference can
 *    round to zero kobo over a short remainder).
 *  - **an upgrade with a difference** — recorded as pending and quoted, and it
 *    stays pending until the prorated charge settles through the ordinary
 *    checkout. Nothing about the subscription moves before then, which is what
 *    stops an unpaid upgrade from being spent.
 *
 * `tier` decides, and price breaks the tie within a rank. Price alone cannot do
 * it: a yearly plan and a monthly plan are not the same kind of number. The
 * tie-break is written down here rather than implied, because an admin can
 * legitimately put a promotion on a higher tier and it should then still read as
 * an upgrade.
 */
final class PlanSelectionService
{
    public function __construct(private readonly SubscriptionTrialSettings $trialSettings) {}

    /**
     * Record the chosen plan on the user, starting the platform trial only when
     * the caller asks for one.
     *
     * A trial starts once: legacy re-ran `now()->addDays()` on every selection,
     * so a trialing user could extend the trial indefinitely by picking a
     * different plan. An existing (even expired) trial end date is never moved.
     *
     * `$startTrial` is the caller's intent and is required rather than assumed.
     * It used to be inferred — any first selection started the trial — which
     * was right while selecting a plan *was* the trial question, and became a
     * bug the moment trial, coupon and pay were three peers on one onboarding
     * page: choosing a plan in order to pay for it would have spent the
     * once-only trial. Defaulting to false is deliberate; defaulting to true
     * would reintroduce exactly that. Both guards still apply on top of the
     * caller's request — the feature must be switched on, and the business must
     * never have had a trial.
     */
    public function select(User $user, SubscriptionPlan $plan, bool $startTrial = false): PlanSelectionResult
    {
        $trial = $this->trialSettings->get();

        $startTrial = $startTrial && $trial['enabled'] && $user->trial_ends_at === null;
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
     * Move an active subscription to a different plan, on the terms described in
     * the class docblock.
     *
     * Returns what happened rather than a subscription: an upgrade has not
     * happened yet, and returning the unchanged subscription beside a message
     * saying it had would be the kind of lie the old code told.
     */
    public function change(User $user, Subscription $subscription, SubscriptionPlan $plan): PlanChangeResult
    {
        $current = $subscription->subscriptionPlan;

        if ($current === null) {
            return PlanChangeResult::refused('Your current plan is no longer available.');
        }

        if ($this->sameRank($current, $plan)) {
            // A different row that grants exactly what the current one does.
            // Charging for it would be charging for nothing, and applying it
            // silently would move the business's billing anchor for no reason.
            return PlanChangeResult::refused(
                "The {$plan->name} plan includes the same as your current plan. There is nothing to change.",
            );
        }

        if (! $this->isUpgrade($current, $plan)) {
            return $this->schedule($user, $subscription, $current, $plan);
        }

        $chargeKobo = $this->proratedDifferenceKobo($current, $plan, $subscription->expires_at);

        if ($chargeKobo <= 0) {
            // An upgrade with nothing to pay — the difference rounds to zero
            // over what is left of the period, or the higher tier is the same
            // price. Applying it directly is right; sending anybody to a gateway
            // for ₦0 is not.
            $this->applyNow($user, $subscription, $current, $plan);

            return PlanChangeResult::applied($plan);
        }

        $this->recordPending($user, $subscription, $current, $plan, $chargeKobo);

        return PlanChangeResult::paymentRequired($plan, $chargeKobo);
    }

    /**
     * Book a downgrade for the end of the period already paid for.
     *
     * Recorded rather than applied, and it also clears any upgrade waiting to be
     * paid for: the two cannot both be in force, and an abandoned upgrade that
     * outlived the intention behind it would surface later as a plan nobody
     * asked for.
     */
    private function schedule(User $user, Subscription $subscription, SubscriptionPlan $current, SubscriptionPlan $plan): PlanChangeResult
    {
        DB::transaction(function () use ($user, $subscription, $current, $plan) {
            $subscription->update([
                'scheduled_plan_id' => $plan->id,
                'scheduled_at' => now(),
                'pending_plan_id' => null,
                'pending_charge_kobo' => null,
                'pending_requested_at' => null,
            ]);

            ActivityRecorder::record(
                action: 'subscription_plan_downgrade_scheduled',
                description: "Subscription downgrade to '{$plan->name}' scheduled for business "
                    ."'{$subscription->business?->name}' (was '{$current->name}')",
                subject: $subscription,
                old: ['plan' => $current->name],
                new: ['plan' => $plan->name],
                actor: $user,
            );
        });

        Log::info('subscription.plan_change_scheduled', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'old_plan_id' => $current->id,
            'new_plan_id' => $plan->id,
            'effective_at' => $subscription->expires_at?->toDateTimeString(),
        ]);

        return PlanChangeResult::scheduled($plan, $subscription->fresh()->expires_at);
    }

    /**
     * A free upgrade: the plan moves now and the paid period is untouched.
     */
    private function applyNow(User $user, Subscription $subscription, SubscriptionPlan $current, SubscriptionPlan $plan): void
    {
        DB::transaction(function () use ($user, $subscription, $current, $plan) {
            $subscription->update([
                'subscription_plan_id' => $plan->id,
                'pending_plan_id' => null,
                'pending_charge_kobo' => null,
                'pending_requested_at' => null,
                'scheduled_plan_id' => null,
                'scheduled_at' => null,
            ]);

            ActivityRecorder::record(
                action: 'subscription_plan_changed',
                description: "Subscription plan changed to '{$plan->name}' for business "
                    ."'{$subscription->business?->name}' (was '{$current->name}')",
                subject: $subscription,
                old: ['plan' => $current->name],
                new: ['plan' => $plan->name],
                actor: $user,
            );
        });

        Log::info('subscription.plan_changed', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'old_plan_id' => $current->id,
            'new_plan_id' => $plan->id,
            'charged_kobo' => 0,
        ]);
    }

    /**
     * Record the upgrade and the figure it was quoted at. Nothing about the plan
     * in force changes here — `subscription_plan_id` moves only when the charge
     * settles, in {@see SubscriptionPaymentRepository::activateSubscription()}.
     */
    private function recordPending(User $user, Subscription $subscription, SubscriptionPlan $current, SubscriptionPlan $plan, int $chargeKobo): void
    {
        DB::transaction(function () use ($subscription, $plan, $chargeKobo) {
            $subscription->update([
                'pending_plan_id' => $plan->id,
                'pending_charge_kobo' => $chargeKobo,
                'pending_requested_at' => now(),
                // An upgrade replaces a booked downgrade: the business has
                // changed its mind in the other direction, and leaving the
                // downgrade booked would move it down at the period end it has
                // just paid to leave behind.
                'scheduled_plan_id' => null,
                'scheduled_at' => null,
            ]);
        });

        Log::info('subscription.plan_change_pending', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'old_plan_id' => $current->id,
            'new_plan_id' => $plan->id,
            'charge_kobo' => $chargeKobo,
        ]);
    }

    /**
     * Whether the two plans are the same purchase.
     *
     * Both halves matter: a plan can be re-ranked without changing its price, and
     * a plan can be re-priced within its rank. Only when neither moved is there
     * genuinely nothing to change.
     */
    private function sameRank(SubscriptionPlan $from, SubscriptionPlan $to): bool
    {
        return (int) $from->tier === (int) $to->tier
            && Naira::koboFromStrict($from->amount) === Naira::koboFromStrict($to->amount);
    }

    /**
     * Rank first, price second — see the class docblock for why the order is
     * that way round and not the other.
     */
    private function isUpgrade(SubscriptionPlan $from, SubscriptionPlan $to): bool
    {
        $fromTier = (int) $from->tier;
        $toTier = (int) $to->tier;

        if ($fromTier !== $toTier) {
            return $toTier > $fromTier;
        }

        return Naira::koboFromStrict($to->amount) > Naira::koboFromStrict($from->amount);
    }

    /**
     * The honest "pay the difference for the rest of the term" figure, in kobo.
     *
     * What is left of the period is valued at each plan's own daily rate and the
     * old plan's remaining value is subtracted from the new one's:
     *
     *     charge = value(new, daysLeft) - value(old, daysLeft)
     *
     * Each side is rounded to the nearest kobo before the subtraction, so both
     * are figures that could be shown to the customer on their own ("your
     * remaining time is worth ₦X"). The result is clamped at zero: a difference
     * that rounds away must not become a one-kobo charge through a provider, and
     * a negative figure is not a refund this platform makes.
     */
    private function proratedDifferenceKobo(SubscriptionPlan $from, SubscriptionPlan $to, ?CarbonInterface $expiresAt): int
    {
        if ($expiresAt === null) {
            return 0;
        }

        // Whole days, rounded up: a few hours left is still a day of service the
        // customer is entitled to, and it is the unit the arithmetic is honest
        // in. A period already over is worth nothing to either plan.
        $daysLeft = (int) ceil(max(0, (int) now()->diffInSeconds($expiresAt, false)) / 86400);

        if ($daysLeft <= 0) {
            return 0;
        }

        return max(0, $this->remainingValueKobo($to, $daysLeft) - $this->remainingValueKobo($from, $daysLeft));
    }

    private function remainingValueKobo(SubscriptionPlan $plan, int $daysLeft): int
    {
        return $this->roundedDiv(
            Naira::koboFromStrict($plan->amount) * $daysLeft,
            $this->periodDays($plan),
        );
    }

    /**
     * How many days one billing period of this plan covers.
     *
     * A month is 30 days and a year is 365, which is the convention the whole
     * platform already uses — {@see SubscriptionPaymentRepository::activateSubscription()}
     * adds a calendar month and a calendar year, and the two agree closely
     * enough that a prorated figure cannot drift by more than a kobo or two
     * across a term. Being exact here would mean per-month period lengths, and
     * the extra precision would only matter on a change made in the last day of
     * February.
     */
    private function periodDays(SubscriptionPlan $plan): int
    {
        $count = max(1, (int) ($plan->interval_count ?: 1));

        return match ($plan->interval) {
            'daily' => $count,
            'weekly' => 7 * $count,
            'yearly' => 365 * $count,
            default => 30 * $count,
        };
    }

    /**
     * Integer division, rounded half up. Money never touches a float.
     */
    private function roundedDiv(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            return 0;
        }

        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
