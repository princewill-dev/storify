<?php

namespace App\Support\Plans;

use App\Http\Controllers\Api\V1\Management\Subscription\PlanController;
use App\Models\SubscriptionPlan;
use App\Observers\SubscriptionPlanObserver;
use App\Repositories\Subscription\SubscriptionPaymentRepository;
use App\Services\Subscription\PlanSelectionService;
use App\Support\Money\Naira;

/**
 * What a year of a tier costs, derived from what a month of it costs.
 *
 * The annual price of a plan is **not** an opinion anybody types. It is the
 * monthly price for twelve months, less {@see self::DISCOUNT_PERCENT}. Every
 * annual row in `subscription_plans` is written from here — by the seeder when
 * the catalogue is laid down, and by {@see SubscriptionPlanObserver}
 * whenever a monthly price moves afterwards — so an admin who re-prices a tier
 * gets the annual figure to follow without touching it.
 *
 * That is the whole of the rule, and it is deliberately in one method. A second
 * copy of "20% off" anywhere would be a second answer to the same question, and
 * the two would drift the first time somebody changed one of them.
 *
 * ## Why the arithmetic is in kobo
 *
 * Money never touches a float on the way to a decision in this codebase —
 * {@see PlanController::plans()}
 * computes its savings percentage in kobo for the same reason. `5000 * 12 * 0.8`
 * happens to land exactly, but `15.7 * 12 * 0.8` does not, and a plan priced at
 * something other than a round thousand is one admin edit away. So the multiply
 * happens in integer kobo and only the finished figure becomes a float.
 */
final class AnnualPricing
{
    /**
     * How much cheaper a year is than paying monthly for the same twelve months.
     *
     * Twenty was chosen because it divides cleanly against the tier ladder the
     * platform sells — ₦5,000, ₦15,000 and ₦50,000 all produce whole naira at
     * 20% off, which a rounder "two months free" (16.67%) does not.
     */
    public const DISCOUNT_PERCENT = 20;

    /**
     * The annual price of a plan whose monthly price is the given amount, in naira.
     */
    public static function annualAmount(int|float|string|null $monthlyAmount): float
    {
        return Naira::floatFromKobo(self::annualKobo($monthlyAmount));
    }

    /**
     * The same figure in kobo, for callers comparing against gateway amounts.
     */
    public static function annualKobo(int|float|string|null $monthlyAmount): int
    {
        $monthlyKobo = Naira::koboFromLenient($monthlyAmount);

        // Twelve months first, then the discount — in that order because it is
        // the order the offer is stated in, and because computing `monthly × 9.6`
        // directly would fold the discount into the multiplier where nobody
        // reading it could see it. Half-up rounding is the `+ 50` before the
        // division, and it only bites on a price that is not a whole thousand.
        return intdiv($monthlyKobo * 12 * (100 - self::DISCOUNT_PERCENT) + 50, 100);
    }

    /**
     * Whether a plan's interval is the annual one.
     *
     * `yearly` is the platform's word for it and the only one that works:
     * {@see SubscriptionPaymentRepository::activateSubscription()}
     * and {@see PlanSelectionService} both compare
     * against that exact string, so a row spelled `annual` would be sold as a
     * year and expire as a month. Anything asking "is this the annual plan?"
     * asks here rather than spelling it out again.
     */
    public static function isAnnual(SubscriptionPlan $plan): bool
    {
        return $plan->interval === 'yearly';
    }
}
