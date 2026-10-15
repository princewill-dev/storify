<?php

namespace App\Http\Resources\Home;

use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One plan card on the marketing home payload.
 *
 * Field names, types and order match the payload the controller built inline:
 * `amount` stays a float because this payload has always been a float, and the
 * integer/bool casts are the casts the previous `map()` applied.
 *
 * Two fields were added when the marketing page grew its billing toggle:
 *
 * - `plan_code`, because the page's call to action now carries the chosen plan
 *   into signup, and `plan_code` — not the numeric id — is the key the rest of
 *   the platform resolves plans by ({@see SubscriptionPlan::getRouteKeyName()}).
 *   Handing over the id would produce a link that works on this endpoint and
 *   nowhere else.
 * - `tier`, because the "Popular" badge has to land on the entry tier of
 *   whichever cadence is on screen. `is_default` cannot do that job: it is a
 *   single flag on a single row, and it now has two cadences to cover.
 *
 * @property-read SubscriptionPlan $resource
 */
final class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SubscriptionPlan $plan */
        $plan = $this->resource;

        return [
            'id' => $plan->id,
            'plan_code' => $plan->plan_code,
            'name' => $plan->name,
            'description' => $plan->description,
            'amount' => (float) $plan->amount,
            'currency' => $plan->currency,
            'interval' => $plan->interval,
            'interval_count' => (int) $plan->interval_count,
            'tier' => (int) $plan->tier,
            'features' => $plan->features ?? [],
            'is_default' => (bool) $plan->is_default,
        ];
    }
}
