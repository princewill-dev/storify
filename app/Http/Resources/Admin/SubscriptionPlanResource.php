<?php

namespace App\Http\Resources\Admin;

use App\Models\SubscriptionPlan;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-11 (admin console) — the subscription-plan row, shaped exactly as the
 * plan CRUD has always emitted it.
 *
 * Field names, types and order are load-bearing: exact-JSON assertions depend
 * on them, so `amount` is the decimal-naira column as a JSON float (never a
 * string) while `amount_kobo` is the same amount in kobo. The kobo conversion
 * rides `Naira::koboFromLenient()` — the named contract the payload's
 * converter was extracted to, chosen because its forgiving parse is the
 * behaviour this read shape has always had; `koboFromStrict` would reject
 * forms this payload must still read.
 *
 * `can_delete` is derived from the guarded-subscription count the list/eager
 * counts load, so the SPA disables Delete up front instead of round-tripping
 * the error. `interval_label` ("monthly", "monthly x3") keeps the legacy
 * list's format without the legacy string-concatenation bug that rendered
 * "monthlyly".
 *
 * @property-read SubscriptionPlan $resource
 */
final class SubscriptionPlanResource extends JsonResource
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
            'amount_kobo' => Naira::koboFromLenient($plan->amount),
            'currency' => $plan->currency,
            'interval' => $plan->interval,
            'interval_count' => (int) $plan->interval_count,
            'interval_label' => $this->intervalLabel($plan->interval, (int) $plan->interval_count),
            'features' => array_values($plan->features ?? []),
            'is_active' => (bool) $plan->is_active,
            'is_default' => (bool) $plan->is_default,
            'is_trial' => (bool) $plan->is_trial,
            'trial_days' => $plan->trial_days !== null ? (int) $plan->trial_days : null,
            'sort_order' => (int) $plan->sort_order,
            'subscriptions_count' => (int) ($plan->subscriptions_count ?? 0),
            'active_subscriptions_count' => (int) ($plan->active_subscriptions_count ?? 0),
            'can_delete' => (int) ($plan->guarded_subscriptions_count ?? 0) === 0,
            'created_at' => $plan->created_at?->toISOString(),
        ];
    }

    /**
     * "monthly", "monthly x3" — the interval column plus its count when >1,
     * the way the legacy list rendered it (without the "monthlyly" bug).
     * Matches the shared plan formatting the admin subscription list uses.
     */
    private function intervalLabel(string $interval, int $intervalCount): string
    {
        return $intervalCount > 1 ? $interval.' x'.$intervalCount : $interval;
    }
}
