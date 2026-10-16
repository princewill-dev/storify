<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the per-plan trial.
 *
 * `subscription_plans.is_trial` and `trial_days` read as "this plan has its own
 * free trial window", and they never meant that. They marked an internal
 * *trial template* — a plan row hidden from every catalogue — and the three
 * methods that would have used them (`SubscriptionPlan::scopeTrial()`,
 * `isTrial()`, `getTrialExpiresAt()`) have no callers anywhere in the codebase.
 *
 * The trial itself was never per-plan. Its length is one platform setting,
 * `settings.trial_days`, read by `SubscriptionTrialSettings` and applied by
 * `PlanSelectionService` — which never consults a plan's `trial_days` at all.
 * So these columns are not a feature being removed; they are a field on an admin
 * form that described a model the platform does not have, which is worse than
 * nothing, because it invites exactly the reading it got.
 *
 * ## What the columns were still doing, and what happens to it
 *
 * `is_trial` had a second job in the admin subscriptions console: a subscription
 * was classified as a trial if its plan was flagged, OR if
 * `metadata->trial` was set. The first half was always false and the second is
 * never written — so that facet has matched nothing since the day it shipped.
 * It now reads `metadata->trial` alone, which is the half that was ever capable
 * of being true.
 *
 * ## Why a row is switched off rather than dropped
 *
 * A row flagged `is_trial` would become visible in the customer catalogue the
 * moment the filter that hides it goes. There is no correct automatic answer for
 * such a row — it is either a leftover or a real plan somebody hid — so it is
 * deactivated rather than deleted, which is the reversible half of the choice,
 * and an admin can see it in the console and decide.
 *
 * The check that prompted this: no plan in the development database has ever had
 * either column set, so on this install the UPDATE below matches nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('subscription_plans', 'is_trial')) {
            DB::table('subscription_plans')
                ->where('is_trial', true)
                ->update(['is_active' => false]);
        }

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['is_trial', 'trial_days']);
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->boolean('is_trial')->default(false)->after('is_active');
            $table->unsignedInteger('trial_days')->nullable()->after('is_trial');
        });
    }
};
