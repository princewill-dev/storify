<?php

namespace App\Services\Admin;

use App\Models\Business;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Subscription\PlanSelectionService;
use App\Support\Plans\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Assigning a plan to a business from the admin console.
 *
 * Nothing could do this before. There was no endpoint, and the business console
 * showed only a plan's name, its status and an end date — so when a support
 * case arrived as "this business needs Enterprise for a week", the answer was
 * to edit the database by hand. That was survivable while a plan meant nothing;
 * it stopped being survivable the moment plans started refusing things, because
 * the moment a limit bites is the moment somebody has to be able to lift it.
 *
 * ## Why this is not the owner's plan change
 *
 * The owner's change and this one write the same column, and they are still
 * deliberately different doors. The owner's is a *purchase*: it quotes a
 * prorated difference and refuses to move anything until the payment clears.
 * This one is a *support action*, and a support action that depends on the
 * customer paying is not a support action — the case that needs it is a
 * customer who cannot pay, or has already paid through some other channel, or
 * is being comped. Charging here would make the console useless exactly when it
 * is needed.
 *
 * So the two must not share a method. They share the *column*, and this class is
 * the second of its two writers — see {@see PlanSelectionService}
 * for the first. Anything that writes `subscriptions.subscription_plan_id`
 * outside one of those two is a bug.
 *
 * ## What "assign" means depends on what the business has
 *
 * There are two states, and the admin means something different in each:
 *
 * 1. **An active subscription.** The plan it pays for is moved, and the paid
 *    period is left exactly where it was — no extension, no truncation. An
 *    admin granting an upgrade is granting the *plan*, not free days, and an
 *    admin fixing a mistake must not silently move a renewal date the business
 *    has been told about either way.
 * 2. **No subscription — a trial, most often.** There is no plan row to move, so
 *    the choice is recorded on the owner (`users.selected_plan_id`), which is
 *    where {@see Entitlements} reads a trial's plan from.
 *    This is how a business is put on a trial *of Enterprise*, which is the
 *    ordinary shape of "let them try the good one for a week".
 *
 * The result says which of the two happened, so the console can report it
 * rather than leaving the admin to guess whether a plan was bought or granted.
 */
final class BusinessPlanService
{
    public const APPLIED_SUBSCRIPTION = 'subscription';

    public const APPLIED_TRIAL = 'trial';

    /**
     * @return array{applied: string, subscription: Subscription|null}
     */
    public function assign(Business $business, SubscriptionPlan $plan, ?User $actor): array
    {
        $subscription = $business->activeSubscription;

        $applied = $subscription === null
            ? self::APPLIED_TRIAL
            : self::APPLIED_SUBSCRIPTION;

        DB::transaction(function () use ($business, $plan, $subscription, $actor) {
            if ($subscription !== null) {
                $old = $subscription->subscriptionPlan?->name;

                $subscription->update(['subscription_plan_id' => $plan->id]);

                ActivityRecorder::record(
                    action: 'business_plan_assigned',
                    description: "Subscription plan changed to '{$plan->name}' for business "
                        ."'{$business->name}'".($old === null ? '' : " (was '{$old}')"),
                    subject: $subscription,
                    old: ['plan' => $old],
                    new: ['plan' => $plan->name],
                    actor: $actor,
                );

                return;
            }

            $owner = $business->owner;

            if ($owner === null) {
                return;
            }

            $old = $owner->selectedPlan?->name;

            $owner->update(['selected_plan_id' => $plan->id]);

            // No subject: the thing that changed is the owner's row, and the
            // audit row is filed against the business being acted on.
            ActivityRecorder::record(
                action: 'business_plan_assigned',
                description: "Trial plan set to '{$plan->name}' for business '{$business->name}'"
                    .($old === null ? '' : " (was '{$old}')"),
                old: ['plan' => $old],
                new: ['plan' => $plan->name],
                actor: $actor,
            );
        });

        Log::info('admin.business_plan_assigned', [
            'business_id' => $business->id,
            'plan_id' => $plan->id,
            'applied' => $applied,
            'actor_id' => $actor?->id,
        ]);

        return [
            'applied' => $applied,
            'subscription' => $subscription?->fresh('subscriptionPlan'),
        ];
    }
}
