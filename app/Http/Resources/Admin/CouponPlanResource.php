<?php

namespace App\Http\Resources\Admin;

use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-12 (admin console) — the coupon form's plan options.
 *
 * The endpoint has only ever emitted these three columns, in this order, off
 * the plan rows (`SubscriptionPlan::orderBy('name')->get(['id', 'name',
 * 'interval'])`) — the rest of the plan (amount, features, trial flags) is
 * not the coupon form's business. No repository method backs this read: it is
 * a single ordered query, and wrapping it would be indirection with no
 * benefit, so the controller keeps the call and this resource only shapes it.
 *
 * @property-read SubscriptionPlan $resource
 */
final class CouponPlanResource extends JsonResource
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
            'interval' => $plan->interval,
        ];
    }
}
