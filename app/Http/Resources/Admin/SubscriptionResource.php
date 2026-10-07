<?php

namespace App\Http\Resources\Admin;

use App\Models\Subscription;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-11 (admin console) — one row of the subscription oversight list.
 *
 * Field names, types and order match the payload this endpoint has always
 * returned (exact-JSON assertions depend on them): `amount` stays the JSON
 * float the schema's decimal naira produced, and the timestamps are ISO-8601
 * strings or null.
 *
 * `amount_kobo` rides `Naira::koboFromLenient()` — the contract the plan
 * trait's `toKobo()` had, and the one Naira records as its legacy source:
 * exact string parsing with a forgiving read of malformed input. The plans
 * endpoint shares the source column, so both keep the same arithmetic.
 *
 * @property-read Subscription $resource
 */
final class SubscriptionResource extends JsonResource
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
            'status_label' => ucfirst($subscription->status),
            'is_active' => $subscription->isActive(),
            'is_trial' => $this->isTrial($subscription),
            'starts_at' => $subscription->starts_at?->toISOString(),
            'expires_at' => $subscription->expires_at?->toISOString(),
            'cancelled_at' => $subscription->cancelled_at?->toISOString(),
            'days_remaining' => $subscription->expires_at && $subscription->expires_at->isFuture()
                ? max(0, (int) now()->diffInDays($subscription->expires_at))
                : null,
            'business' => $subscription->business ? [
                'id' => $subscription->business->id,
                'name' => $subscription->business->name,
                'business_code' => $subscription->business->business_code,
                'status' => $subscription->business->status,
            ] : null,
            'plan' => $plan ? [
                'id' => $plan->id,
                'name' => $plan->name,
                'plan_code' => $plan->plan_code,
                'amount' => (float) $plan->amount,
                'amount_kobo' => Naira::koboFromLenient($plan->amount),
                'currency' => $plan->currency,
                'interval' => $plan->interval,
                'interval_count' => (int) $plan->interval_count,
                'interval_label' => $this->intervalLabel($plan->interval, (int) $plan->interval_count),
                'is_trial' => (bool) $plan->is_trial,
            ] : null,
            'started_with' => [
                'coupon_code' => data_get($subscription->metadata, 'coupon_code'),
                'early_pass_code' => data_get($subscription->metadata, 'early_pass_code'),
                'payment_skipped' => (bool) data_get($subscription->metadata, 'payment_skipped', false),
            ],
        ];
    }

    /**
     * Trial-ness can be recorded in two places: a trial-plan template
     * (`is_trial`) or the metadata flag. The filter, the counts and this
     * payload all read both.
     */
    private function isTrial(Subscription $subscription): bool
    {
        return (bool) ($subscription->subscriptionPlan?->is_trial
            || data_get($subscription->metadata, 'trial', false));
    }

    /**
     * "monthly", "monthly x3" — the interval column plus its count when >1,
     * the way the legacy list rendered it (without the legacy "monthlyly"
     * string-concatenation bug).
     */
    private function intervalLabel(string $interval, int $intervalCount): string
    {
        return $intervalCount > 1 ? $interval.' x'.$intervalCount : $interval;
    }
}
