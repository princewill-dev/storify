<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use App\Support\Plans\EntitlementRegistry;
use App\Support\Plans\Entitlements;
use Illuminate\Database\Seeder;

/**
 * The platform's opening offer: three tiers, seeded once.
 *
 * ## What this seeder used to do, and why it had to stop
 *
 * It ran `updateOrCreate(['name' => ...])` with `'plan_code' => Str::random(24)`
 * inside the *value* array, so every run wrote a fresh `plan_code` onto both
 * plans. Two things followed from that:
 *
 * 1. **Saved links broke.** `plan_code` is the route key — checkout URLs use it
 *    — so re-seeding invalidated every URL anybody had been sent, and any
 *    subscription row or bookmark pointing at a plan by its code.
 * 2. **Admin work was destroyed.** It also ran
 *    `whereNotIn('name', ['Starter Monthly', 'Starter Annual'])->update(['is_active' => false])`,
 *    which switched off every plan an admin had created, and
 *    `where('is_default', true)->update(['is_default' => false])`, which took the
 *    default away from whichever plan had been chosen to hold it. A seeder that
 *    undoes the console is a seeder that cannot be run.
 *
 * So this rewrite is a correctness fix first and a tier change second:
 * `firstOrCreate` on the name, nothing written into a row that already exists,
 * no deactivation sweep and no default-flag shuffle. Running it against a live
 * database does nothing at all once the three tiers are there, whatever the
 * admin has since done to them.
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
 * ## These are defaults, not fixtures
 *
 * Names, prices and intervals are the seeder's opening bid. What it asserts is
 * the *shape*: three tiers, all ten keys stated on every one of them, storefronts
 * everywhere, three stores at the entry tier and twenty at the second — and that
 * the ladder climbs, which is the property a price list exists to have.
 *
 * The bidding is not quite the last word on an existing database, and this is
 * worth knowing before changing a number here expecting it to land: because
 * `firstOrCreate` writes nothing to a row that is already there, `Entry` and
 * `Growth` will keep whatever price they were created with, and so would
 * `Enterprise` had 2026_10_14_000005 not re-applied this file's price and copy
 * to every recognised row. Read that migration before re-pricing a tier: it runs
 * on the next deploy, once, and the console is where a price is changed
 * afterwards.
 *
 * ## The default plan, and why it is stated here — but only where it is free
 *
 * The entry tier carries `is_default`, which is what
 * {@see Entitlements::for()} resolves a trial to when the owner has never picked
 * a plan. Leaving it to the console is how the flag ends up on a superseded row:
 * on the database this was written against, `Vendor` and `Starter` — two legacy
 * rows that state no entitlements at all — held it between them, so every new
 * signup was entitled by a row nobody had looked at in months.
 * 2026_10_14_000004_retire_superseded_plan_rows.php retires those two and moves
 * the flag here; this seeder states it so a fresh install never repeats the
 * mistake.
 *
 * It states it **only when no plan holds it already** — see `$defaultIsHeld` in
 * `run()`. Two of the three routes to a second flagged row are silent and worth
 * naming: an admin who renames the entry tier, after which `firstOrCreate`
 * matches nothing and creates a fresh row under the old name; and an admin who
 * simply promotes a plan of their own in the console. Either leaves two rows
 * carrying `is_default`, and `active()->default()->first()` then picks between
 * them by whatever order the table happens to return. Stating the flag
 * unconditionally is only safe while the row it is stated on already exists.
 *
 * ## What this seeder cannot do, and what does it instead
 *
 * `firstOrCreate` means this file describes plans that do not exist yet and
 * leaves every row that already exists exactly as it was — including rows whose
 * name it recognises. On a database seeded by an older version, the `Enterprise`
 * row it would match is a *legacy* row with `entitlements = null`: this seeder
 * matches it, writes nothing, and reports success. So the three tiers below are
 * the definition of the opening offer, and the migrations do the work on rows
 * that are already there:
 *
 * - 2026_10_14_000002_fill_plan_entitlements_from_tier_names.php — the first
 *   seven keys onto recognised rows
 * - 2026_10_14_000003_fill_module_entitlements_from_tier_names.php — `pos`,
 *   `plugins` and `invoices`, added later
 * - 2026_10_14_000004_retire_superseded_plan_rows.php — switching off the rows
 *   this seeder supersedes, and moving the default
 * - 2026_10_14_000005_apply_seeded_tier_pricing_and_copy.php — the price and the
 *   copy of a recognised row, which is the half of a tier `firstOrCreate` cannot
 *   reach at all. A recognised row that already exists keeps whatever it was
 *   created with, and the `Enterprise` row on this platform was created with
 *   `Entry`'s price.
 *
 * `TIERS` is public so those migrations and this seeder read one definition
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
        $defaultTier = array_key_first(self::TIERS);

        // Read once, before anything is created, and only claim the default if
        // nothing at all holds it. Stating it unconditionally would be safe for
        // `firstOrCreate` on one row and unsafe *across* rows: an admin who
        // renames the entry tier leaves this seeder matching nothing, so it
        // creates a fresh row under the old name — and a second row carrying the
        // default, between which {@see Entitlements::for()} then picks
        // arbitrarily. So a database with no plans gets the flag on the entry
        // tier, fresh install included; a database that already has one keeps
        // whatever an admin chose, and the retirement migration is what moves it
        // off a superseded row.
        $defaultIsHeld = SubscriptionPlan::where('is_default', true)->exists();

        foreach (self::TIERS as $tier => $plan) {
            // `firstOrCreate`, so an existing row is left completely alone —
            // including its `plan_code`, which is a live URL, and including
            // `is_default`, which is why the flag below is safe to state here.
            // Everything inside this array is written once and owned by the
            // admin from then on; this seeder is an opening offer, not a
            // reconciliation.
            SubscriptionPlan::firstOrCreate(
                ['name' => $plan['name']],
                [
                    'description' => $plan['description'],
                    'amount' => $plan['amount'],
                    'currency' => 'NGN',
                    'interval' => 'monthly',
                    'interval_count' => 1,
                    'is_active' => true,
                    // The entry tier takes the default where nobody holds it,
                    // rather than leaving it to the console, because the
                    // alternative is not "nobody holds it" but "a row nobody
                    // meant holds it": `is_default` decides what a trial whose
                    // owner has never picked a plan resolves to
                    // ({@see Entitlements::for()}), and leaving it unset means
                    // that trial is entitled by whichever row happens to be
                    // flagged — which is how a legacy row ends up deciding the
                    // entitlements of every new signup. See `$defaultIsHeld`
                    // above for when this is written and when it stands down.
                    //
                    // If you are reading this because the default has moved:
                    // that is a console action and this seeder will not undo it,
                    // both because `firstOrCreate` writes nothing to a row that
                    // is already there and because a flag that is already held
                    // is never claimed here. Re-seeding is safe.
                    'is_default' => $tier === $defaultTier && ! $defaultIsHeld,
                    'is_trial' => false,
                    'trial_days' => null,
                    'tier' => $tier,
                    'sort_order' => $tier,
                    'features' => $plan['features'],
                    'entitlements' => $plan['entitlements'],
                ]
            );
        }
    }
}
