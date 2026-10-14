<?php

use App\Models\SubscriptionPlan;
use App\Support\Plans\Entitlements;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Switch off the plan rows the three seeded tiers supersede, and move the default.
 *
 * ## What is being retired, and why
 *
 * The platform's opening offer is three tiers — `Entry`, `Growth`, `Enterprise`
 * — and on the database this was written against there are two other rows beside
 * them: `Vendor` and `Starter`, both from seeders older than the tier catalogue.
 * Neither states a single entitlement. Between them they hold the live
 * subscriptions of three businesses, and **`Starter` holds `is_default`** —
 * which is the flag that decides what a trial whose owner has never picked a
 * plan is entitled to ({@see Entitlements::for()}).
 *
 * That last part is the actual defect, and it is worth stating plainly: a new
 * business signing up and starting a trial was being entitled by a row nobody
 * had looked at in months, whose entitlements are null, so every one of its
 * keys resolved to a registry default. The platform was not selling what it
 * thought it was selling to its own trials.
 *
 * ## What this does not do
 *
 * **It does not delete anything, and it does not move anybody.** Retiring a plan
 * is not the same as ending a subscription: the three businesses on these rows
 * keep their plan, keep their entitlements, and keep working. What changes is
 * that the rows are no longer offered for sale and no longer decide what a
 * trial gets. Moving those businesses onto a tier is a conversation, not a
 * migration — the console is where it happens, when somebody has looked at what
 * each business actually needs.
 *
 * **It does not create the tiers.** `Entry` and `Growth` are created by
 * {@see SubscriptionPlanSeeder}, which is a separate command by design. This
 * migration moves the default onto `Entry` *if it is there* and logs it if it is
 * not, so the two can run in either order:
 *
 * - **migrate then seed** — this leaves no default, logs that, and the seeder
 *   creates `Entry` with `is_default` set. Between the two the platform has no
 *   default plan for a moment; a trial resolving in that window falls through to
 *   the registry defaults, which grant exactly what `Entry` grants (three stores,
 *   a storefront, no books, no till, no gateway). Nothing is taken away.
 * - **seed then migrate** — `Entry` exists, and this sets the flag on it.
 *
 * Both orders converge on the same state, which is why neither has to be
 * enforced in a deploy script.
 *
 * ## Why the names are written here rather than read from the seeder
 *
 * The rows being retired are **not** tiers, so they are not in
 * {@see SubscriptionPlanSeeder::TIERS} and giving them a home there would be
 * describing them as part of the offer. They are named here, once, as what they
 * are: two specific legacy rows on a specific database. Re-running this
 * migration is impossible — migrations run once — and the operation is
 * idempotent anyway, so a database that never had them is unaffected.
 */
return new class extends Migration
{
    /**
     * Rows the tier catalogue supersedes.
     *
     * Matched by name because these rows have no reliable marker: `plan_code` was
     * regenerated on every run of the seeder that created them, so it identifies
     * nothing.
     *
     * @var array<int, string>
     */
    private const SUPERSEDED = ['Vendor', 'Starter'];

    public function up(): void
    {
        $tierNames = array_column(SubscriptionPlanSeeder::TIERS, 'name');
        $entryPlanName = $tierNames[0] ?? null;

        $retired = [];

        foreach (SubscriptionPlan::query()->whereIn('name', self::SUPERSEDED)->get() as $plan) {
            // Only rows that are still offered are reported as retired, so the
            // log says what changed rather than what was already true.
            $retired[$plan->name] = [
                'was_active' => (bool) $plan->is_active,
                'was_default' => (bool) $plan->is_default,
                'subscriptions' => $plan->subscriptions()->count(),
            ];

            $plan->forceFill(['is_active' => false, 'is_default' => false])->save();
        }

        // Cleared everywhere first, then set on the entry tier where it exists.
        // Writing it directly would be the same thing on this database, but not
        // on one where some other row had been flagged — and two rows holding
        // `is_default` is a state nothing downstream handles: `scopeDefault()`
        // takes the first and a trial's entitlements then depend on row order.
        SubscriptionPlan::query()->where('is_default', true)->update(['is_default' => false]);

        $entry = $entryPlanName === null
            ? null
            : SubscriptionPlan::query()->where('name', $entryPlanName)->first();

        if ($entry !== null) {
            $entry->forceFill(['is_default' => true])->save();
        }

        Log::info('plans.superseded_rows_retired', [
            'retired' => $retired,
            'default_moved_to' => $entry?->name,
            // Whatever holds the default now, read back rather than assumed —
            // if this is null, nothing does, and the seeder has not run yet.
            'default_now_held_by' => SubscriptionPlan::query()
                ->where('is_default', true)
                ->value('name'),
            'entry_tier_absent' => $entry === null,
        ]);
    }

    /**
     * Nothing to undo.
     *
     * Reactivating rows on rollback would put two superseded plans back on sale
     * and hand the default back to whichever one held it — silently re-entitling
     * every planless trial to whatever a legacy row happens to resolve to. A
     * rollback should not be a way to un-decide a product decision.
     */
    public function down(): void
    {
        //
    }
};
