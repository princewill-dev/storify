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
        ];
    }
}
