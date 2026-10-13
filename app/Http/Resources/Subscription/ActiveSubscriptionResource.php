<?php

namespace App\Http\Resources\Subscription;

use App\Models\Subscription;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The business's active subscription block: identity, term, the plan it is on
 * and the amount that will be billed at the next renewal.
 *
 * `next_amount` stays a JSON float and `next_amount_kobo` its kobo equivalent,
 * both exactly as the endpoint has always emitted them (kobo via the forgiving
 * exact parse the payload carried before).
 */
final class ActiveSubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Subscription $subscription */
        $subscription = $this->resource;
        $plan = $subscription->subscriptionPlan;

        return [
            'id' => $subscription->id,
            'subscription_code' => $subscription->subscription_code,
            'status' => $subscription->status,
            'is_active' => $subscription->isActive(),
            'starts_at' => $subscription->starts_at?->toISOString(),
            'expires_at' => $subscription->expires_at?->toISOString(),
            'plan' => $plan ? SubscriptionPlanResource::make($plan)->resolve($request) : null,
            'next_amount' => $plan ? (float) $plan->amount : null,
            'next_amount_kobo' => $plan ? Naira::koboFromLenient($plan->amount) : null,
            'billing_cycle' => $plan ? SubscriptionPlanResource::intervalLabel($plan) : null,

            // A change in flight, in either direction. Both are null in the
            // ordinary state, and the two are different facts: a pending change
            // is waiting on the customer's money, a scheduled one is waiting on
            // the calendar. The screen has to say which, because only one of
            // them can be acted on.
            //
            // `pending_plan` is what the plan *will* be, never what it is —
            // `plan` above stays the plan in force, so nothing in the payload
            // suggests an unpaid upgrade has already landed.
            'pending_plan' => $subscription->pendingPlan
                ? SubscriptionPlanResource::make($subscription->pendingPlan)->resolve($request)
                : null,
            'pending_charge_kobo' => $subscription->pending_charge_kobo,
            'pending_requested_at' => $subscription->pending_requested_at?->toISOString(),
            'scheduled_plan' => $subscription->scheduledPlan
                ? SubscriptionPlanResource::make($subscription->scheduledPlan)->resolve($request)
                : null,
            // When a booked downgrade takes effect, which is the end of the
            // period already paid for — `expires_at`, stated here so the screen
            // does not have to know that.
            'scheduled_effective_at' => $subscription->hasScheduledChange()
                ? $subscription->expires_at?->toISOString()
                : null,
        ];
    }
}
