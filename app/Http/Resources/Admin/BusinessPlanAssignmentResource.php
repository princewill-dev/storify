<?php

namespace App\Http\Resources\Admin;

use App\Models\Business;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Support\Plans\Entitlements;
use App\Support\Plans\StoreQuota;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the console gets back after assigning a plan, and what it needs to settle
 * afterwards without a second round trip.
 *
 * The row is a plain object rather than a model, because this is not a resource
 * for a table — it is the outcome of an action, and it gathers four things that
 * only exist together at this moment: which business, which plan, which way the
 * assignment landed, and what the business is entitled to now.
 *
 * `entitlements` and `store_quota` ride along because the two questions an admin
 * asks immediately afterwards are "what did that just give them" and "did that
 * unblock the thing they rang about" — and both are answerable here for the price
 * of one count. Sending the admin back to the business detail screen to find out
 * would be the console asking them to check its work.
 */
final class BusinessPlanAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Business $business */
        $business = $this->resource->business;
        /** @var SubscriptionPlan $plan */
        $plan = $this->resource->plan;
        /** @var Subscription|null $subscription */
        $subscription = $this->resource->subscription;

        // Re-read, because the assignment has just happened and the in-memory
        // business was loaded before it.
        $fresh = $business->fresh();
        $entitlements = Entitlements::for($fresh);

        return [
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
            ],
            'plan' => SubscriptionPlanResource::make($plan)->resolve(),
            // 'subscription' when a paid plan was moved, 'trial' when the choice
            // was recorded on the owner because there was no subscription to
            // move. The console reports the difference rather than letting the
            // admin guess whether they have just granted access or sold it.
            'applied' => $this->resource->applied,
            'subscription' => $subscription === null ? null : [
                'id' => $subscription->id,
                'subscription_code' => $subscription->subscription_code,
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at?->toISOString(),
                // Unchanged by an assignment, and shown so that is visible: an
                // admin granting a plan must not be able to move a renewal date
                // they were not asked to move.
                'expires_at' => $subscription->expires_at?->toISOString(),
            ],
            'entitlements' => $entitlements->payload(),
            'store_quota' => StoreQuota::payload($fresh),
        ];
    }
}
