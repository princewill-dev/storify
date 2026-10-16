<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use App\Observers\SubscriptionPlanObserver;
use App\Support\Plans\AnnualPricing;
use App\Support\Plans\EntitlementRegistry;
use App\Support\Plans\Entitlements;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * The platform's plan catalogue: three tiers in two cadences, and nothing else.
 *
 * ## Two rows per tier
 *
 * `Entry`, `Growth` and `Enterprise` each exist **twice** — billed monthly and
 * billed yearly — so the catalogue a run leaves behind is six rows, not three.
 *
 * The twins deliberately share a `name`. The toggle on both pricing pages
 * already says which cadence you are reading, so a card showing "Entry · per
 * year" is the whole story and "Entry Annual · per year" tells it twice.
 * `name` is not unique in the schema, so nothing stops this — but it is why
 * {@see self::run()} matches rows on `name` **and** `interval` rather than on
 * name alone. Keyed on the name, the second pass would find the first pass's
 * row every time and overwrite the monthly price with the annual one.
 *
 * **Only the monthly price is stated below.** An annual amount is never typed:
 * it is {@see AnnualPricing::annualAmount()} of its monthly twin, which is 20%
 * off twelve months. Changing a tier's price therefore means changing one number
 * in `TIERS` and nothing else, and an admin who re-prices a tier from the
 * console gets the same treatment through
 * {@see SubscriptionPlanObserver} — the two agree because they
 * call one method rather than restating one rule.
 *
 * The twins are otherwise identical — same tier, same description, same
 * features, same entitlements. Cadence is a question of *when you pay*, not
 * *what you get*, so a business on the annual plan holds exactly what a business
 * on the monthly plan holds. That is load-bearing for
 * {@see Entitlements::planGranting()}: it scans plans for the cheapest that
 * grants a toggle, and two rows per tier means it will find the monthly one
 * first. Handing out a different entitlement set per cadence would make that
 * answer depend on which row the scan happened to reach.
 *
 * ## What this seeder does now
 *
 * It **reconciles**. Every run rewrites `Entry`, `Growth` and `Enterprise` from
 * {@see self::TIERS} and deletes every other row in `subscription_plans`. What
 * the catalogue holds after a run is what is written below, whatever it held
 * before — including rows the console created, which do not survive.
 *
 * That is a reversal, and it was the owner's decision rather than this file's
 * idea. The rewrite that came before matched on `name` with `firstOrCreate`:
 * create if absent, write nothing into a row that already exists. Its virtue was
 * that re-seeding could never undo console work or invalidate a live
 * `plan_code`; its cost was that nothing could ever *remove* a plan. On the
 * database this was written against, the plans page was advertising five —
 * `Entry`, `Growth` and `Enterprise` beside `Vanilla` at ₦75,000 and `Start up`
 * at ₦100,000, two rows no current seeder had created and nothing was ever going
 * to take away. Re-running the seeder, which is what an owner reasonably does
 * when a page looks wrong, changed nothing at all.
 *
 * The trade is the other way now: this file is the source of truth for the
 * catalogue.
 *
 * ## What that costs, stated plainly
 *
 * **Deleting a plan deletes its subscriptions.**
 * `subscriptions.subscription_plan_id` is `onDelete('cascade')`, so a business
 * sitting on a plan this seeder removes loses that plan outright. It is not
 * moved to `Entry` first, and the seeder does not refuse. Every row about to be
 * destroyed is logged with the number of subscriptions going with it, and that
 * line is the only account of it that will ever exist.
 *
 * **It bypasses a guard the console enforces on itself.**
 * `SubscriptionPlanController::destroy()` refuses to delete a plan carrying
 * active, pending or suspended subscriptions and tells the admin to deactivate
 * it instead. This seeder does exactly what that guard exists to prevent. That
 * is deliberate and known rather than an oversight to be tidied up later: it is
 * the owner's operation, run by hand, against a catalogue that had stopped being
 * manageable.
 *
 * **A rename is a delete.** Matching is by name, so an admin who renames the
 * entry tier to something else gets that row deleted — cascading whatever was
 * subscribed to it — and a fresh `Entry` created in its place. There is no
 * `plan_code` to match on instead: rows created by an older seeder have none.
 *
 * ## The faults the rewrite before this one fixed, which are still fixed
 *
 * Neither is worth reintroducing, and the first is why `plan_code` is absent
 * from the values below:
 *
 * 1. **Saved links.** It ran `updateOrCreate` with `'plan_code' => Str::random(24)`
 *    inside the *values* array, so every run rewrote the code. `plan_code` is
 *    the route key — checkout URLs use it — so re-seeding invalidated every URL
 *    anybody had been sent. {@see SubscriptionPlan} fills it on create and only
 *    when empty, so a surviving tier keeps the code its links are published
 *    under.
 * 2. **Admin work.** It ran
 *    `whereNotIn('name', ['Starter Monthly', 'Starter Annual'])->update(['is_active' => false])`,
 *    switching off every plan an admin had created, and cleared `is_default` on
 *    every row. The default is still claimed below, but only because this seeder
 *    now owns the whole table: with every other row deleted there is no admin
 *    choice left for it to undo.
 *
 * ## Why the flag is cleared before it is set
 *
 * {@see Entitlements::for()} resolves a trial whose owner has never picked a
 * plan through `active()->default()->first()`, and `first()` between two flagged
 * rows returns whichever the table offers first. Clearing every row before
 * setting the entry tier's flag means the catalogue never passes through a state
 * that has two.
 *
 * ## Three tiers, and the top one holds the whole catalogue
 *
 * `Entry`, `Growth`, `Enterprise` — and the ladder is the product: the entry
 * tier is a working shop (three stores, a storefront each, the till and
 * invoices but not the books, plugins or a gateway of its own), the second buys
 * scale (twenty stores, higher staff and warehouse ceilings), and the third is
 * the only plan that holds **every** key in {@see EntitlementRegistry} with no
 * limit left standing.
 *
 * That last property is load-bearing rather than tidy:
 * {@see Entitlements::planGranting()} answers "which plan would give me this?"
 * by scanning plans for the cheapest that grants the toggle, so every upgrade
 * prompt the platform writes points at Enterprise. A key the top tier did not
 * state would send an owner to the most expensive plan on the platform for
 * something it does not actually include.
 *
 * ## The values below are the catalogue, not fixtures
 *
 * Names, prices and intervals are the offer, and because they are written on
 * every run, editing a number here is now the whole of a price change — there is
 * no reconciliation migration to write beside it. What that costs is that the
 * console can no longer hold a price: an admin who re-prices a tier from the
 * admin panel keeps it only until the next seed.
 *
 * What the tests pin is the *shape*: three tiers, all ten keys stated on every
 * one of them, storefronts everywhere, three stores at the entry tier and twenty
 * at the second — and that the ladder climbs, which is the property a price list
 * exists to have.
 *
 * ## What this seeder does not do
 *
 * It writes rows, not history. A database that has never been seeded gets the
 * three tiers and nothing else, which is the intent. A database seeded by an
 * *older* version is the case the delete exists for: rows a previous seeder
 * created under other names are removed rather than reconciled, because there is
 * nothing in them this file could recognise.
 * 2026_10_14_000004_retire_superseded_plan_rows.php was the earlier answer to
 * that problem, and it retired `Vendor` and `Starter` by name — rows this
 * database does not have, which is why the plans page stayed wrong. It remains
 * as history; this seeder is what clears a catalogue now.
 *
 * `TIERS` is public so the tests, and
 * 2026_10_14_000005_apply_seeded_tier_pricing_and_copy.php, read one definition
 * rather than several that drift.
 */
class SubscriptionPlanSeeder extends Seeder
{
    /**
     * The opening tiers, keyed by the rank a plan change compares on.
     *
     * `stores_limit` of `null` is unlimited, and it is deliberate that only the
     * top tier has it: escalating store counts is most of what the ladder sells,
     * and the step from 20 to no ceiling is the one that is worth its price.
     */
    public const TIERS = [
        1 => [
            'name' => 'Entry',
            'description' => 'For a first shop — up to three stores, each with its own storefront.',
            'amount' => 5000.00,
            'features' => [
                'Up to 3 stores',
                'An online storefront for every store',
                'Unlimited products',
                'Order and customer management',
                'Transaction tracking and reports',
                'Email support',
            ],
            'entitlements' => [
                EntitlementRegistry::STORES => 3,
                EntitlementRegistry::STAFF => null,
                EntitlementRegistry::PRODUCTS => null,
                EntitlementRegistry::WAREHOUSES => null,
                EntitlementRegistry::STOREFRONT => true,
                EntitlementRegistry::OWN_GATEWAY => false,
                // Stated rather than left to the registry default. The default
                // is also false, so this changes nothing — but a plan that
                // *says* a thing is a decision, and one that says nothing is
                // indistinguishable afterwards from a plan nobody has got to
                // yet. The tier that grants the books is the one worth reading
                // reasons out of; this one is worth being explicit about.
                EntitlementRegistry::ACCOUNTING => false,
                // Stated for a different reason than accounting's: these three
                // have no history, so saying `false` is what keeps the entry
                // tier from being entitled by *omission* the day somebody
                // changes a registry default. A plan that says nothing is one
                // edit away from saying yes.
                EntitlementRegistry::POS => false,
                EntitlementRegistry::PLUGINS => false,
                EntitlementRegistry::INVOICES => false,
            ],
        ],

        2 => [
            'name' => 'Growth',
            'description' => 'For a business that has outgrown one shop — up to twenty stores.',
            'amount' => 15000.00,
            'features' => [
                'Up to 20 stores',
                'An online storefront for every store',
                'Unlimited products',
                'Order and customer management',
                'Transaction tracking and reports',
                'Priority support',
            ],
            'entitlements' => [
                EntitlementRegistry::STORES => 20,
                EntitlementRegistry::STAFF => null,
                EntitlementRegistry::PRODUCTS => null,
                EntitlementRegistry::WAREHOUSES => null,
                EntitlementRegistry::STOREFRONT => true,
                EntitlementRegistry::OWN_GATEWAY => false,
                EntitlementRegistry::ACCOUNTING => false,
                EntitlementRegistry::POS => false,
                EntitlementRegistry::PLUGINS => false,
                EntitlementRegistry::INVOICES => false,
            ],
        ],

        3 => [
            'name' => 'Enterprise',
            'description' => 'Every module the platform has, and no ceiling on what it holds.',
            'amount' => 50000.00,
            'features' => [
                'Unlimited stores',
                'An online storefront for every store',
                'Unlimited products',
                'The point of sale, for every store',
                'Connect your own payment provider',
                'Accounting, expenses, bills and financial reports',
                'Invoices, and plugins for marketing and analytics',
                'Transaction tracking and reports',
                'Priority support',
            ],
            'entitlements' => [
                // The top tier, and the shape of it is deliberate: every limit
                // is `null` (unlimited) and every toggle is `true`. This is the
                // one plan that holds the whole catalogue, which is what makes
                // it the plan the upgrade prompt names for anything an owner is
                // missing — {@see Entitlements::planGranting()} reads that off
                // this map, so a key left out here would send somebody to the
                // top tier for something it does not actually include.
                EntitlementRegistry::STORES => null,
                EntitlementRegistry::STAFF => null,
                EntitlementRegistry::PRODUCTS => null,
                EntitlementRegistry::WAREHOUSES => null,
                EntitlementRegistry::STOREFRONT => true,
                EntitlementRegistry::OWN_GATEWAY => true,
                EntitlementRegistry::ACCOUNTING => true,
                EntitlementRegistry::POS => true,
                EntitlementRegistry::PLUGINS => true,
                EntitlementRegistry::INVOICES => true,
            ],
        ],
    ];

    public function run(): void
    {
        // Cleared before it is set. `is_default` is read as
        // `active()->default()->first()`, and two flagged rows make that
        // whichever the table offers first. Everything that is not a tier is
        // deleted below, so the only row left able to hold it is the entry tier.
        SubscriptionPlan::where('is_default', true)->update(['is_default' => false]);

        $defaultTier = array_key_first(self::TIERS);

        // The ids of the rows this run leaves standing. Deleting by *name*
        // instead would spare a duplicate — two rows called `Entry`, where
        // `updateOrCreate` matched only one — and the whole point of this seeder
        // is that the catalogue afterwards is exactly these six.
        $keep = [];

        // Monthly first. The order only matters in that a tier's monthly row
        // exists before its annual twin is written, which is what
        // {@see \App\Observers\SubscriptionPlanObserver} reads the price from —
        // though this pass states the annual amount itself and does not rely
        // on the observer having run.
        foreach (self::TIERS as $tier => $plan) {
            $keep[] = $this->writeTier(
                tier: $tier,
                plan: $plan,
                interval: 'monthly',
                amount: (float) $plan['amount'],
                isDefault: $tier === $defaultTier,
            );
        }

        foreach (self::TIERS as $tier => $plan) {
            $keep[] = $this->writeTier(
                tier: $tier,
                plan: $plan,
                interval: 'yearly',
                // Never typed. See the class docblock: the annual price is a
                // fact about the monthly price, not an independent offer.
                amount: AnnualPricing::annualAmount($plan['amount']),
                // The default stays on the monthly entry tier. It answers
                // "which plan does a trial with no selection fall back to?"
                // ({@see Entitlements::for()}), and the twins carry identical
                // entitlements, so pointing it at the annual row would change
                // nothing except which name that fallback reports.
                isDefault: false,
            );
        }

        SubscriptionPlan::whereNotIn('id', $keep)
            ->get()
            ->each(function (SubscriptionPlan $plan): void {
                // Logged before it goes, because the cascade this triggers is
                // silent and unrecoverable: `subscriptions.subscription_plan_id`
                // is `onDelete('cascade')`, so this line is the only record of a
                // business that has just lost its plan.
                Log::warning('SubscriptionPlanSeeder removed a plan that is not a tier.', [
                    'plan' => $plan->name,
                    'plan_code' => $plan->plan_code,
                    'amount' => $plan->amount,
                    'subscriptions_deleted' => $plan->subscriptions()->count(),
                ]);

                $plan->delete();
            });
    }

    /**
     * Write one row of the ladder and return its id.
     *
     * Both cadences of a tier go through here so they cannot drift in anything
     * except the three things that are supposed to differ — the interval, the
     * amount and the sort order. Description, features, tier and entitlements
     * are the same on both rows by construction rather than by two lists being
     * kept in step by hand.
     *
     * @param  array<string, mixed>  $plan  One entry from {@see self::TIERS}.
     */
    private function writeTier(int $tier, array $plan, string $interval, float $amount, bool $isDefault): int
    {
        return SubscriptionPlan::updateOrCreate(
            // Matched on the cadence as well as the name. The twins share a
            // name deliberately (see the class docblock), so matching on the
            // name alone would find the monthly row on the annual pass and
            // overwrite its price with the annual one.
            ['name' => $plan['name'], 'interval' => $interval],
            [
                'description' => $plan['description'],
                'amount' => $amount,
                'currency' => 'NGN',
                'interval_count' => 1,
                'is_active' => true,
                // Claimed outright, not only where nobody holds it. The
                // version of this seeder that shipped before could not take
                // the flag off an admin's plan without re-entitling every
                // trial that had never chosen one; this one deletes that
                // plan, so the choice it would have undone is gone anyway.
                'is_default' => $isDefault,
                'tier' => $tier,
                // Monthly rows sort first, then the annual ones, so the admin
                // list reads as the ladder twice rather than interleaved. The
                // offset is the tier count rather than a literal, so adding a
                // fourth tier cannot collide with the annual row of the first.
                'sort_order' => $interval === 'yearly' ? count(self::TIERS) + $tier : $tier,
                'features' => $plan['features'],
                'entitlements' => $plan['entitlements'],
                // `plan_code` is deliberately absent. It is the route key,
                // published in checkout URLs, and the model fills it on
                // create and only when empty — rewriting it here is the
                // fault that broke every saved link.
            ]
        )->id;
    }
}
