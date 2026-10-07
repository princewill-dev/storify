<?php

namespace App\Http\Resources\Subscription;

use App\Models\SubscriptionPlan;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * A plan as the WS-07 management subscription screens present it: the "choose
 * your plan" grid (with its monthly/yearly/other groups), the selected plan and
 * the plan nested in an active subscription.
 *
 * Field names, types and order are load-bearing — tests assert the exact JSON,
 * so `amount` is the decimal column as a JSON float while `amount_kobo` is the
 * same amount in kobo. The kobo conversion rides Naira::koboFromLenient(), the
 * exact-but-forgiving parse this payload has always used; it is not
 * interchangeable with the strict gateway contract.
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
            'interval_label' => self::intervalLabel($plan),
            'features' => $plan->features ?? [],
            'is_default' => (bool) $plan->is_default,
            'trial_days' => $plan->trial_days !== null ? (int) $plan->trial_days : null,
            'sort_order' => (int) $plan->sort_order,
        ];
    }

    /**
     * @param  Collection<int, SubscriptionPlan>  $plans
     * @return array<int, array<string, mixed>>
     */
    public static function rows(Collection $plans): array
    {
        return $plans
            ->map(fn (SubscriptionPlan $plan) => self::make($plan)->resolve())
            ->all();
    }

    /**
     * "/monthly" — the interval with the platform's leading slash, plus the
     * legacy plural marker appended to the whole word for a count above one
     * ("/monthlys"). The format is asserted by the API tests; not a typo.
     */
    public static function intervalLabel(SubscriptionPlan $plan): string
    {
        return '/'.$plan->interval.((int) $plan->interval_count > 1 ? 's' : '');
    }
}
