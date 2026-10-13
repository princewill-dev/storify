<?php

use App\Support\Plans\EntitlementRegistry;
use App\Support\Plans\Entitlements;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a plan contains, and where it sits in the order.
 *
 * Two columns, and both answer a question the plan table could not answer
 * before. `entitlements` is what the plan actually grants — see
 * {@see EntitlementRegistry} for why that was a display
 * string until now and what changed. `tier` is the rank a plan change compares
 * on: whether moving from A to B is an upgrade or a downgrade is asked of
 * `tier`, and it cannot be asked of the price, because a yearly plan's amount
 * and a monthly plan's amount are not the same kind of number. `sort_order`
 * cannot answer it either — it means display order, and the seeder already has
 * yearly sorting *before* monthly, so an admin reordering the list would
 * silently reorder the tiers.
 *
 * ## Additive, deliberately, with no backfill
 *
 * Existing rows get `entitlements = null` and `tier = 1`, and
 * {@see Entitlements} reads a missing key as the registry's
 * default. Nothing is written into anybody's plan here, because any value this
 * migration invented would be a guess about what a plan the admin created
 * eighteen months ago was supposed to mean — and a guess written into the
 * column is indistinguishable afterwards from a decision somebody made. The
 * defaults in the registry are the platform's stated position; the admin edits
 * from there.
 *
 * `features` is untouched. It is the marketing bullet list, four resources and
 * the marketing site serialize it raw, and it is not what this column replaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            // Nullable so that "states nothing" stays representable, which is
            // what lets the registry default apply per key rather than the whole
            // map being all-or-nothing.
            $table->json('entitlements')->nullable()->after('features');

            // Rank, not price, decides what counts as an upgrade. 1 is the
            // entry tier; the admin sets the rest.
            $table->unsignedTinyInteger('tier')->default(1)->after('entitlements');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['entitlements', 'tier']);
        });
    }
};
