<?php

use App\Support\Plans\EntitlementRegistry;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Put `pos`, `plugins` and `invoices` into the plan rows that are already there.
 *
 * This is
 * 2026_10_14_000002_fill_plan_entitlements_from_tier_names.php a second time,
 * for three keys that did not exist when that migration ran. It exists as a
 * separate file rather than an edit to that one because that one has already
 * been applied — a migration that has run is a record of what was done, not a
 * description of what should be true, and editing it would change nothing on any
 * database it has already touched.
 *
 * ## Why these three need a backfill at all
 *
 * They are toggles that default to `false`. A row that states nothing gets the
 * default, so on a database whose plans predate these keys, every plan —
 * including the top tier — would refuse POS, plugins and invoices the moment the
 * routes carried the gate. That is the same trap as `accounting`, and the same
 * answer: the plans that should grant them have to say so.
 *
 * ## The rules are the earlier migration's rules, unchanged
 *
 * - **Only keys the row is silent about are written.** A plan that already
 *   states `pos => false` keeps it. This migration cannot overwrite an admin's
 *   decision, only fill silence.
 * - **Only rows that already exist are touched.** No plans are created. A
 *   database without `Entry` and `Growth` still has no `Entry` and `Growth`
 *   afterwards.
 * - **Unrecognised rows are left alone and logged.** A row whose name matches no
 *   tier resolves to the registry defaults, which is where it already was.
 * - **`down()` is a no-op**, for the reason the earlier migration gives:
 *   removing the keys on rollback would silently un-gate three modules.
 *
 * ## What it will not do
 *
 * It does not switch anything off and it does not move `is_default`. Retiring the
 * rows this seeder supersedes is
 * 2026_10_14_000004_retire_superseded_plan_rows.php, which is deliberately a
 * different migration: filling silence is additive and safe to reason about
 * locally, and deactivating a row somebody may be subscribed to is not.
 */
return new class extends Migration
{
    /**
     * The keys this migration is responsible for.
     *
     * Named explicitly rather than "every key", because a later migration will
     * be the one responsible for whatever is added after this. A migration that
     * filled every key in the catalogue would quietly grow a new job every time
     * somebody added an entitlement — and would then be doing work that belongs
     * to a file written for it.
     *
     * @var array<int, string>
     */
    private const KEYS = [
        EntitlementRegistry::POS,
        EntitlementRegistry::PLUGINS,
        EntitlementRegistry::INVOICES,
    ];

    public function up(): void
    {
        $plans = DB::table('subscription_plans')
            ->select('id', 'name', 'entitlements')
            ->get();

        $filled = [];
        $unchanged = [];

        foreach (SubscriptionPlanSeeder::TIERS as $tierDefinition) {
            $row = $plans->firstWhere('name', $tierDefinition['name']);

            if ($row === null) {
                continue;
            }

            $stored = json_decode((string) $row->entitlements, true);
            $stored = is_array($stored) ? $stored : [];

            $merged = $stored;
            $added = [];

            foreach (self::KEYS as $key) {
                // The tier does not state it, so this migration has no opinion
                // and writes nothing. A `null` here would be a *stated* null,
                // which reads as a toggle that is off for a reason nobody
                // recorded — worse than the silence it replaced.
                if (! array_key_exists($key, $tierDefinition['entitlements'])) {
                    continue;
                }

                // `array_key_exists`, not `isset`, on the stored map too. A key
                // stated as `false` is a decision, and `isset` would read it as
                // an absence and overwrite it — the one mistake here that would
                // hand a module to a plan that had been told it could not have
                // it.
                if (! array_key_exists($key, $merged)) {
                    $merged[$key] = $tierDefinition['entitlements'][$key];
                    $added[$key] = $merged[$key];
                }
            }

            if ($added === []) {
                $unchanged[] = $row->name;

                continue;
            }

            DB::table('subscription_plans')
                ->where('id', $row->id)
                ->update(['entitlements' => json_encode($merged)]);

            $filled[] = ['name' => $row->name, 'added' => $added];
        }

        $recognised = array_column(SubscriptionPlanSeeder::TIERS, 'name');

        Log::info('plans.module_entitlements_filled', [
            'filled' => $filled,
            'already_stated' => $unchanged,
            'unrecognised' => $plans->pluck('name')
                ->reject(fn (string $name) => in_array($name, $recognised, true))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Nothing to undo — see the class docblock and the earlier migration.
     */
    public function down(): void
    {
        //
    }
};
