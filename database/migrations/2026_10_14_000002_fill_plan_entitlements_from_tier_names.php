<?php

use App\Services\Admin\SubscriptionPlanService;
use App\Support\Plans\EntitlementRegistry;
use App\Support\Plans\Entitlements;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Put the platform's tiers into the plan rows that are already there.
 *
 * ## The problem this exists to solve
 *
 * {@see SubscriptionPlanSeeder} describes three tiers and runs `firstOrCreate`
 * on `name`. That is deliberate — it is what stops a seeder from rewriting a
 * live `plan_code` and destroying admin work — but it has a consequence nobody
 * had to look at until an entitlement started *gating* something: **a row that
 * already exists is never told what its tier is.** On the database this was
 * written against, `Entry` and `Growth` do not exist at all, the `Enterprise`
 * row is a legacy one from an older seeder, and every plan row reads
 * `entitlements = null, tier = 1`. Nothing grants anything, and the per-business
 * `businesses.can_connect_own_gateway` column is quietly doing the job the
 * `own_gateway` entitlement was built for.
 *
 * That was survivable while nothing read the column. It stops being survivable
 * the moment `accounting` defaults to `false`: a row that *states nothing* and a
 * row that *states "no accounting"* are the same row to
 * {@see Entitlements::effective()}, so the Enterprise customer who is paying for
 * the books would lose them at deploy. The platform needs Enterprise to say yes.
 *
 * ## Why this differs from the "no backfill" rule
 *
 * 2026_10_13_000004 added `entitlements` and `tier` with **no backfill**, and was
 * right to: nothing read the column, and any value that migration invented would
 * be indistinguishable afterwards from a decision somebody made. The difference
 * now is that a gate reads it — the guess is no longer avoidable, only
 * *governable*. Two rules govern it:
 *
 * 1. **Only keys the row is silent about are written.** A plan that already
 *    states `own_gateway => false` keeps it. This is the same boundary
 *    {@see SubscriptionPlanService::entitlements()} draws for the admin editor —
 *    an edit that says nothing about a key leaves that key alone — and it means
 *    this migration cannot overwrite an admin's decision, only fill silence.
 * 2. **Only rows that already exist are touched.** No plans are created. A
 *    database without `Entry` and `Growth` still has no `Entry` and `Growth`
 *    afterwards; that is a content gap and not this migration's business.
 *
 * ## What it will not do
 *
 * A plan whose name matches no tier — `Starter` and `Vendor` on the database
 * this was written against — is left exactly as it is, `entitlements = null`,
 * and logged as unrecognised. Those rows resolve to the registry defaults, which
 * is the behaviour they have had since the column was added. Note that
 * **`is_default` sits on `Starter`** on that database, so a trial whose owner
 * never picked a plan resolves to `Starter` and gets no accounting. Whether
 * trials should include the books is a plan edit in the console, not a line of
 * code.
 *
 * ## Reading the result
 *
 * This logs one `plans.entitlements_filled` line naming the plans it filled, the
 * keys it added to each, the plans it recognised but did not need to change, and
 * the rows it did not recognise at all. On a fresh or an already-correct
 * database it fills nothing and says so. The row-level check is:
 *
 *     select name, tier, entitlements from subscription_plans order by tier;
 *
 * The one scenario worth checking before deploying: if the plan that should
 * grant the books is named something other than `Enterprise`, this migration
 * skips it. That is a row-level fix — `accounting` is in the admin plan editor —
 * or one more named migration, not a change to this one.
 */
return new class extends Migration
{
    /**
     * The keys a plan row is allowed to be told about, per matched name.
     *
     * Read from the seeder rather than restated here, so the tiers have one
     * definition and cannot drift apart. The tradeoff is accepted and worth
     * naming: a migration that reads a class which can change after it has run
     * is a migration whose behaviour is not frozen. The alternative — a second
     * copy of three tier definitions — is the drift this codebase keeps paying
     * for, and the cost here is bounded because the merge is "only where the row
     * is silent", so a later change to the seeder cannot rewrite a filled row.
     */
    public function up(): void
    {
        $plans = DB::table('subscription_plans')
            ->select('id', 'name', 'tier', 'entitlements')
            ->get();

        $filled = [];
        $unchanged = [];

        foreach (SubscriptionPlanSeeder::TIERS as $tier => $tierDefinition) {
            $row = $plans->firstWhere('name', $tierDefinition['name']);

            // Named by the seeder, absent from this database. Creating it is a
            // product decision about what the platform sells, not a repair.
            if ($row === null) {
                continue;
            }

            $stored = json_decode((string) $row->entitlements, true);
            $stored = is_array($stored) ? $stored : [];

            $merged = $stored;
            $added = [];

            foreach ($tierDefinition['entitlements'] as $key => $value) {
                if (! array_key_exists($key, $merged)) {
                    $merged[$key] = $value;
                    $added[$key] = $value;
                }
            }

            $tierChanged = (int) $row->tier !== (int) $tier;

            if ($added === [] && ! $tierChanged) {
                $unchanged[] = $row->name;

                continue;
            }

            DB::table('subscription_plans')
                ->where('id', $row->id)
                // Kept in the registry's key order so the column reads the same
                // way for every plan, and so a diff of two plans is legible.
                ->update([
                    'entitlements' => json_encode(self::ordered($merged)),
                    'tier' => $tier,
                ]);

            $filled[] = [
                'name' => $row->name,
                'added' => $added,
                'tier' => ['from' => (int) $row->tier, 'to' => (int) $tier],
            ];
        }

        $recognised = array_column(SubscriptionPlanSeeder::TIERS, 'name');

        Log::info('plans.entitlements_filled', [
            'filled' => $filled,
            'already_stated' => $unchanged,
            'unrecognised' => $plans->pluck('name')
                ->reject(fn (string $name) => in_array($name, $recognised, true))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Nothing to undo.
     *
     * The same reasoning that made 2026_10_13_000004 additive applies in
     * reverse: `null` and `{accounting: false}` mean the same thing to
     * {@see Entitlements}, so there is no state to restore, and deleting the keys
     * again would silently un-gate a feature on a rollback — turning a rollback
     * into an outage for whoever was using it.
     */
    public function down(): void
    {
        //
    }

    /**
     * The merged map in the catalogue's own key order, so the stored JSON reads
     * the same way for every plan.
     *
     * Keys the catalogue does not know are kept rather than dropped: this
     * migration fills silence, and cleaning up a key left behind by an
     * entitlement that no longer exists is {@see EntitlementRegistry::normalize()}'s
     * job at the write boundary, not a data migration's job at deploy time.
     *
     * @param  array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private static function ordered(array $map): array
    {
        $ordered = [];

        foreach (array_keys(EntitlementRegistry::all()) as $key) {
            if (array_key_exists($key, $map)) {
                $ordered[$key] = $map[$key];
            }
        }

        foreach ($map as $key => $value) {
            if (! array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        return $ordered;
    }
};
