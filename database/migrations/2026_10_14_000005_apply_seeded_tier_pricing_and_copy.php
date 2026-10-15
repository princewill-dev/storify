<?php

use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Put the published ladder's price and copy onto the plan rows that are already
 * there.
 *
 * ## The defect this exists for
 *
 * The `Enterprise` row on this platform was created by an older seeder, and
 * `SubscriptionPlanSeeder` matches rows by name with `firstOrCreate` — which
 * writes nothing at all to a row that already exists. So when the three-tier
 * ladder was written, `Enterprise` kept the old seeder's price: **₦5,000 a
 * month, the same as `Entry`**, with a description and a feature list from
 * before the till, invoices, plugins and the books existed. The plans page was
 * therefore selling the top tier as a duplicate of the cheapest one, and
 * describing it as smaller than it now is.
 *
 * The entitlements were already right — 2026_10_14_000002 and ..._000003 filled
 * those — which is exactly why this was easy to miss: the gate allowed
 * everything the top tier should allow, and only the page that sells it was
 * wrong.
 *
 * ## What it writes, and what it does not
 *
 * For each tier in {@see SubscriptionPlanSeeder::TIERS}, on the row whose name
 * matches: `amount`, `currency`, `interval`, `interval_count`, `description`
 * and `features`. Those six are the *commercial definition* of the tier — what
 * the plans page renders and what a customer is asked to pay — and they live in
 * one place, the seeder, so that a ladder is described once.
 *
 * Deliberately not written: `is_active`, `is_default`, `plan_code`, `is_trial`,
 * `trial_days` and `sort_order`. Activation and the default are console
 * decisions with their own migration (…_000004 does the retirement and moves the
 * flag). `plan_code` is a live URL. Trial configuration is not part of what a
 * tier costs. And `sort_order` is the admin's arrangement of the page rather
 * than a property of the tier.
 *
 * ## The trade this makes, stated plainly
 *
 * **This overwrites.** Every earlier migration in this series filled silence and
 * could not touch a decision; this one cannot tell "the old seeder's price" from
 * "the price an admin chose", because both are a number in a column. It is
 * written because the ladder had to be corrected and because the row it changes
 * is the one whose price was indistinguishable from the cheapest tier's — a
 * ladder nobody chooses on purpose.
 *
 * A migration runs once, so the window for this to undo an admin's re-pricing is
 * the deploy itself. It logs every change as `was`/`now` for exactly that
 * reason: read `plans.tier_pricing_applied` in the deploy log, and if it moved a
 * price that was deliberately set, set it back in the console. The console stays
 * the place to re-price a plan afterwards — this file is a reconciliation, not a
 * reconciler.
 *
 * ## Rows it will not touch
 *
 * Names matching no tier are left alone and logged. On this platform that is
 * `Vendor` and `Starter` — superseded, switched off by …_000004, and holding
 * entitlements of `null`, so their price is nobody's business any more.
 *
 * `down()` is a no-op. The previous price was not recorded anywhere the database
 * can reach, and inventing one on rollback would be worse than leaving the
 * ladder standing.
 */
return new class extends Migration
{
    /**
     * Billing terms the seeder states once for every tier rather than per tier.
     *
     * Written for the same reason the price is: a tier that says it costs
     * ₦50,000 a month and then charges annually is a worse failure than a wrong
     * number, and both come from the same silence in `firstOrCreate`.
     *
     * @var array<string, mixed>
     */
    private const BILLING = [
        'currency' => 'NGN',
        'interval' => 'monthly',
        'interval_count' => 1,
    ];

    public function up(): void
    {
        // Monthly rows only. Every tier now exists twice — once a month and once
        // a year — and `keyBy('name')` keeps the **last** row it sees for a name,
        // so without this filter the reconciliation would have landed on each
        // tier's annual twin: the monthly ladder it exists to put back would
        // have been left alone, and the annual row would have had its price and
        // its interval rewritten to the monthly figures. Scoping the read is
        // what keeps this file doing the one thing its docblock describes.
        $plans = DB::table('subscription_plans')
            ->where('interval', self::BILLING['interval'])
            ->get()
            ->keyBy('name');

        $changed = [];
        $unchanged = [];

        foreach (SubscriptionPlanSeeder::TIERS as $tier) {
            $row = $plans->get($tier['name']);

            if ($row === null) {
                continue;
            }

            $update = [];
            $was = [];
            $now = [];

            foreach ($this->expected($tier) as $column => $wanted) {
                if (! $this->differs($column, $row->{$column} ?? null, $wanted)) {
                    continue;
                }

                $update[$column] = $column === 'features' ? json_encode($wanted) : $wanted;

                // Logged decoded, so the deploy log reads as the ladder does
                // rather than as two JSON blobs.
                $was[$column] = $column === 'features'
                    ? (json_decode((string) $row->features, true) ?: [])
                    : $row->{$column};
                $now[$column] = $wanted;
            }

            if ($update === []) {
                $unchanged[] = $tier['name'];

                continue;
            }

            DB::table('subscription_plans')
                ->where('id', $row->id)
                ->update($update);

            $changed[] = ['name' => $tier['name'], 'was' => $was, 'now' => $now];
        }

        $recognised = array_column(SubscriptionPlanSeeder::TIERS, 'name');

        Log::info('plans.tier_pricing_applied', [
            'changed' => $changed,
            'already_correct' => $unchanged,
            'unrecognised' => $plans->keys()
                ->reject(fn (string $name): bool => in_array($name, $recognised, true))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Nothing to undo — see the class docblock.
     */
    public function down(): void
    {
        //
    }

    /**
     * The commercial definition of a tier, as the seeder states it.
     *
     * @param  array<string, mixed>  $tier
     * @return array<string, mixed>
     */
    private function expected(array $tier): array
    {
        return array_merge(self::BILLING, [
            'amount' => $tier['amount'],
            'description' => $tier['description'],
            'features' => $tier['features'],
        ]);
    }

    /**
     * Whether a stored value differs from the seeder's — by value, not by
     * encoding, so a correct row is never rewritten for the sake of a key order
     * or the `decimal:2` cast's trailing zeros.
     */
    private function differs(string $column, mixed $stored, mixed $wanted): bool
    {
        if ($column === 'features') {
            $decoded = json_decode((string) $stored, true);

            return (is_array($decoded) ? $decoded : []) != $wanted;
        }

        if ($column === 'amount' || $column === 'interval_count') {
            return (float) $stored !== (float) $wanted;
        }

        return $stored != $wanted;
    }
};
