<?php

namespace App\Support\Plans;

use App\Models\SubscriptionPlan;
use App\Repositories\Subscription\SubscriptionPaymentRepository;
use App\Services\Subscription\PlanSelectionService;
use Carbon\CarbonInterface;

/**
 * How long one billing period of a plan lasts, in days and as a date.
 *
 * Two shapes of the same fact, because two different questions get asked of it.
 * Proration needs a **count of days** to divide an amount over — see
 * {@see PlanSelectionService}. A subscription that is
 * being given a fresh term needs a **date** to expire at — see
 * {@see SubscriptionPaymentRepository::applyPlanChange()}.
 * They were answered in two places before this class existed, and the monthly and
 * annual answers have to agree or a prorated figure stops matching the term it
 * was charged against.
 *
 * ## The convention, and why it is a convention
 *
 * A month is 30 days and a year is 365. Neither is exact — a month is 28 to 31,
 * and a year is 365 or 366 — and {@see self::endFromNow()} is deliberately not
 * the same arithmetic as {@see self::days()}: it adds a calendar month or a
 * calendar year, which is what a customer's renewal date should actually be. The
 * two agree closely enough that a prorated figure cannot drift by more than a
 * kobo or two across a term, and being exact in `days()` would mean per-month
 * period lengths, where the extra precision would only matter on a change made
 * on the last day of February.
 *
 * ## `interval_count`
 *
 * A plan can be sold as "every three months" — `interval_count` of 3 on a
 * monthly interval — and both methods multiply by it. Nothing in the seeded
 * ladder uses a count above one today; the multiplication is here because the
 * column exists and a plan that says "3" while being billed for 1 would be
 * wrong in the direction that costs the customer money.
 */
final class PlanPeriod
{
    /**
     * How many days one billing period covers, for proration.
     */
    public static function days(SubscriptionPlan $plan): int
    {
        $count = max(1, (int) ($plan->interval_count ?: 1));

        return match ($plan->interval) {
            'daily' => $count,
            'weekly' => 7 * $count,
            'yearly' => 365 * $count,
            default => 30 * $count,
        };
    }

    /**
     * The moment a period starting now would end.
     *
     * A calendar month or a calendar year, not 30 or 365 days — this is the date
     * a renewal lands on, and a subscription renewing on the 3rd of every month
     * should keep renewing on the 3rd.
     */
    public static function endFromNow(SubscriptionPlan $plan): CarbonInterface
    {
        $count = max(1, (int) ($plan->interval_count ?: 1));

        return match ($plan->interval) {
            'daily' => now()->addDays($count),
            'weekly' => now()->addWeeks($count),
            'yearly' => now()->addYears($count),
            default => now()->addMonths($count),
        };
    }

    /**
     * Whether moving from one plan to the other lengthens the commitment.
     *
     * Same tier, different cadence, longer period — monthly to annual, and
     * nothing else. This is the one kind of change that hands out a **fresh
     * term**, and it is a narrower thing than "an upgrade": moving up a tier
     * buys more of the same period and keeps the renewal date, while moving to a
     * longer cadence is buying a longer period and has to move it.
     *
     * The distinction matters because the proration formula is blind to it. It
     * values the new plan only over what is *left of the old period*, so an
     * annual plan — whose per-day rate is lower than its monthly twin's — always
     * prices as negative against one, clamps to zero, and would be handed over
     * for nothing with the term untouched. A customer clicking "annual" would
     * have bought a year and kept three weeks.
     */
    public static function isExtension(?SubscriptionPlan $from, SubscriptionPlan $to): bool
    {
        if ($from === null) {
            return false;
        }

        return (int) $from->tier === (int) $to->tier
            && $from->interval !== $to->interval
            && self::days($to) > self::days($from);
    }
}
