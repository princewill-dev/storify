<?php

namespace App\Observers;

use App\Http\Requests\Admin\SubscriptionPlanRequest;
use App\Models\SubscriptionPlan;
use App\Services\Admin\SubscriptionPlanService;
use App\Support\Money\Naira;
use App\Support\Plans\AnnualPricing;
use Illuminate\Support\Facades\Log;

/**
 * Keeps an annual plan's price equal to its monthly twin's, discounted.
 *
 * The catalogue holds two rows per tier — `Entry` monthly and `Entry` yearly,
 * and the same for `Growth` and `Enterprise`. The monthly row is the one that
 * carries a price a person chose; the annual row's price is derived from it, and
 * this is what derives it. Re-price a tier in the admin console and the annual
 * twin follows in the same request, which is what the owner asked for when they
 * said the annual price should be computed "in case admin changes the plans in
 * the future".
 *
 * ## Why an observer and not a service or a seeder value
 *
 * A price can be changed from more than one place — the admin console through
 * {@see SubscriptionPlanService}, `tinker`, a migration, the
 * seeder — and every one of them ends in a `save()`. Hooking the model catches
 * all of them without asking each caller to remember. The seeder writes the
 * computed figure too, so the catalogue on a fresh database is correct without
 * this ever firing; the two agree because they call the same
 * {@see AnnualPricing} method rather than restating the rule.
 *
 * ## It is authoritative, including against the admin console
 *
 * A hand-typed annual price is corrected, not honoured — see the warning this
 * logs. That is deliberate: the owner asked for the discount to be computed so
 * it cannot drift, and a field that is sometimes respected is worse than one
 * that never is. The admin console does not silently lose the edit, because
 * {@see SubscriptionPlanRequest} refuses a changed
 * annual amount up front and says which plan to edit instead. This is the
 * backstop for every path that is not the admin console.
 *
 * ## Why `saveQuietly`
 *
 * The correction is a save, and a save is what this listens for. `saveQuietly`
 * is the one save that does not fire model events, so the correction cannot
 * re-enter. It is also why the twins converge in one step rather than settling
 * through a second pass.
 */
class SubscriptionPlanObserver
{
    /**
     * Cadences that pair up. A `daily` or `weekly` plan has no twin and is left
     * alone — the ladder this rule exists for is the monthly/annual one, and
     * inventing a discount for a cadence nobody sells would be guessing.
     */
    private const PAIRED_INTERVALS = ['monthly', 'yearly'];

    public function saved(SubscriptionPlan $plan): void
    {
        // A trial plan is not part of the ladder and carries no annual twin.
        if ($plan->is_trial || ! in_array($plan->interval, self::PAIRED_INTERVALS, true)) {
            return;
        }

        $twin = $this->twinOf($plan);

        if ($twin === null) {
            return;
        }

        // Whichever of the pair is monthly is the one that holds the price; the
        // yearly one is derived from it. Written this way round, rather than
        // branching on which row happened to be saved, so an edit to either row
        // resolves to the same answer.
        $monthly = $plan->interval === 'monthly' ? $plan : $twin;
        $annual = $plan->interval === 'yearly' ? $plan : $twin;

        $expected = AnnualPricing::annualKobo($monthly->amount);

        if (Naira::koboFromStrict($annual->amount) === $expected) {
            return;
        }

        Log::warning('subscription_plans.annual_price_recomputed', [
            'plan' => $annual->name,
            'tier' => $annual->tier,
            'plan_code' => $annual->plan_code,
            'was' => $annual->amount,
            'monthly_amount' => $monthly->amount,
            'now' => Naira::floatFromKobo($expected),
            'discount_percent' => AnnualPricing::DISCOUNT_PERCENT,
        ]);

        $annual->amount = Naira::floatFromKobo($expected);
        $annual->saveQuietly();
    }

    /**
     * The other cadence at the same tier, if the catalogue holds one.
     *
     * Ordered by id so a catalogue that somehow carries two monthly rows at one
     * tier still resolves to the same twin on every save rather than picking
     * whichever the table offers first. The seeder guarantees one row per tier
     * per cadence, so this only matters for rows an admin created by hand.
     */
    private function twinOf(SubscriptionPlan $plan): ?SubscriptionPlan
    {
        return SubscriptionPlan::query()
            ->where('tier', $plan->tier)
            ->where('is_trial', false)
            ->whereIn('interval', self::PAIRED_INTERVALS)
            ->where('interval', '!=', $plan->interval)
            ->orderBy('id')
            ->first();
    }
}
