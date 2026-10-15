<?php

namespace App\Support\Plans;

use App\Http\Controllers\Api\V1\Management\Subscription\PlanController;
use App\Support\Money\Naira;
use Illuminate\Support\Collection;

/**
 * How much a year saves against paying monthly, as a whole percentage.
 *
 * Two pricing surfaces show this number — the dashboard's plan grid and the
 * marketing site's — and they must not disagree about it, so it is computed
 * here rather than in either controller. It lived in
 * {@see PlanController}
 * until the marketing page needed it too, at which point a second copy was the
 * only alternative to moving it.
 *
 * It compares the **cheapest of each**, not a tier against its own twin. The
 * number is a headline ("Save 20%"), read by somebody deciding whether annual is
 * worth it at all, and the cheapest plan is the one they are most likely looking
 * at. Every tier is discounted by the same rate, so the headline is the same
 * wherever it is read from — and if that ever stops being true, the cheapest
 * pair is the honest one to quote.
 */
final class CadenceSavings
{
    /**
     * The saving as an integer percentage, or null when it cannot be stated.
     *
     * Null rather than zero when either cadence is missing: a catalogue with no
     * annual plans has not "saved nothing", it has nothing to compare, and a
     * badge reading "Save 0%" would be a claim rather than an absence.
     *
     * Computed in kobo so no float arithmetic touches money, and rounded to the
     * nearest whole percent — `intdiv($saving * 100 + intdiv($annual, 2), $annual)`.
     */
    public static function yearlyPercent(Collection $monthlyPlans, Collection $yearlyPlans): ?int
    {
        if ($monthlyPlans->isEmpty() || $yearlyPlans->isEmpty()) {
            return null;
        }

        $annualMonthlyCost = Naira::koboFromLenient($monthlyPlans->min('amount')) * 12;
        $cheapestYearly = Naira::koboFromLenient($yearlyPlans->min('amount'));

        if ($annualMonthlyCost <= 0) {
            return null;
        }

        $saving = $annualMonthlyCost - $cheapestYearly;

        return intdiv($saving * 100 + intdiv($annualMonthlyCost, 2), $annualMonthlyCost);
    }
}
