<?php

namespace App\Repositories\Subscription;

use App\Models\Payment;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Collection;

/**
 * Query layer for the WS-07 subscription screens: the plan catalogue the
 * "choose your plan" grid renders, the plan a selection request may target,
 * and the business's subscription billing history.
 *
 * Read-only, like every repository here: it never opens a transaction, never
 * aborts a request and never shapes a response.
 */
final class SubscriptionPlanRepository
{
    /**
     * The selectable plan catalogue, in the order the grid renders it.
     *
     * @return Collection<int, SubscriptionPlan>
     */
    public function activeCatalogue(): Collection
    {
        return SubscriptionPlan::query()
            ->active()
            ->where('is_trial', false)
            ->orderBy('sort_order')
            ->orderBy('amount')
            ->get();
    }

    /**
     * The plan a select/change request may target: active and not a trial
     * plan, so neither endpoint can be pointed at an internal trial row.
     */
    public function findSelectable(int $planId): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->active()
            ->where('is_trial', false)
            ->find($planId);
    }

    /**
     * The business's subscription payments, newest first.
     *
     * Deliberately not scoped to the *active* subscription: legacy scoped it
     * that way, so a business's payment history vanished the moment its
     * subscription expired. Every subscription payment is kept.
     *
     * @return Collection<int, Payment>
     */
    public function recentBillingHistory(?int $businessId, int $limit = 20): Collection
    {
        // forBusiness() is a no-op for a null id, so the guard belongs here:
        // without it a user with no business would read every business's
        // payments.
        if (! $businessId) {
            return new Collection;
        }

        return Payment::query()
            ->forBusiness($businessId)
            ->whereNotNull('subscription_id')
            ->latest()
            ->take($limit)
            ->get();
    }
}
