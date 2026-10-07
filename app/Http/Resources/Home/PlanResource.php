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
            'name' => $plan->name,
            'description' => $plan->description,
            'amount' => (float) $plan->amount,
            'currency' => $plan->currency,
            'interval' => $plan->interval,
            'interval_count' => (int) $plan->interval_count,
            'features' => $plan->features ?? [],
            'is_default' => (bool) $plan->is_default,
        ];
    }
}
