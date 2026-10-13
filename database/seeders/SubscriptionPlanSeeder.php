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
 * ## Why three new plans rather than reshaping the two existing ones
 *
 * `Starter Monthly` and `Starter Annual` are rows somebody may already be
 * subscribed to, and `Starter Annual` holds `is_default` — which is what decides
 * the entitlements of a trial whose owner never picked a plan
 * ({@see Entitlements}). Rewriting them in place would change
 * what live subscribers are entitled to, silently, at deploy time. Adding the
 * tiers leaves every existing row exactly as it was, and the admin reconciles
 * from the console: rename, re-price, or switch off whatever is superseded.
 *
 * ## These are defaults, not fixtures
 *
 * Names, prices and intervals are the seeder's opening bid, and every one of
 * them is the admin's to change afterwards — which is the point of storing
 * entitlements as data. What the seeder does assert is the *shape* the owner
 * asked for: three tiers, storefronts everywhere, three stores at the entry
 * tier, twenty at the second, and the top tier unlimited with its own gateway.
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
    private const TIERS = [
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
            ],
        ],

        3 => [
            'name' => 'Enterprise',
            'description' => 'Unlimited stores, and payments taken through your own payment provider.',
            'amount' => 50000.00,
            'features' => [
                'Unlimited stores',
                'An online storefront for every store',
                'Unlimited products',
                'Connect your own payment provider',
                'Transaction tracking and reports',
                'Priority support',
            ],
            'entitlements' => [
                // The whole of what this tier adds on top of the one below it,
                // and both are deliberate: no ceiling on stores, and the keys.
                EntitlementRegistry::STORES => null,
                EntitlementRegistry::STAFF => null,
                EntitlementRegistry::PRODUCTS => null,
                EntitlementRegistry::WAREHOUSES => null,
                EntitlementRegistry::STOREFRONT => true,
                EntitlementRegistry::OWN_GATEWAY => true,
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::TIERS as $tier => $plan) {
            // `firstOrCreate`, so an existing row is left completely alone —
            // including its `plan_code`, which is a live URL. Everything inside
            // this array is created once and owned by the admin from then on.
            SubscriptionPlan::firstOrCreate(
                ['name' => $plan['name']],
                [
                    'description' => $plan['description'],
                    'amount' => $plan['amount'],
                    'currency' => 'NGN',
                    'interval' => 'monthly',
                    'interval_count' => 1,
                    'is_active' => true,
                    // Never the default. `is_default` decides what a trial
                    // nobody has picked a plan for resolves to, and quietly
                    // moving it here would re-entitle every such trial at deploy
                    // time — see Entitlements. Which plan holds it stays a
                    // console decision.
                    'is_default' => false,
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
