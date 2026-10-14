<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two things the signup flow has to remember about a business.
 *
 * **`onboarding_intents`** is what the person answered on "How can we be of
 * help" — a multi-select, so JSON rather than a column per answer. It is
 * deliberately *not* a set of switches that gate the product: the owner's
 * decision was that the answer shapes the experience and hides nothing. It is
 * read back in exactly two places, and this list is the whole of its use:
 * pre-ticking the toggles on the store form, and ordering the cards on
 * `/welcome`. Anywhere that reads it more broadly than that is a bug, not a
 * feature.
 *
 * **`welcome_seen_at`** is what makes the `/welcome` interstitial force itself
 * once. It lives on the *business*, not on the user, because that is what it is
 * a fact about: a cashier hired next month must not be shown their employer's
 * onboarding checklist, and must not clear it either by loading a page.
 *
 * ## The backfill is not optional
 *
 * Every business that already exists has already been onboarded. Their owners
 * have a store, a plan and orders; the only thing they lack is a timestamp
 * saying they do not need to be welcomed. Shipping these columns without the
 * `UPDATE` below would send every one of them to an onboarding interstitial at
 * their next login — a wedged session, in production, for the entire installed
 * base, from a column that no code reads until the moment it does.
 *
 * So the backfill is the statement that carries the deploy, not a trailing
 * nicety: at the instant `welcome_seen_at` becomes readable, it is already set
 * for everybody who predates it. Only businesses created after this migration
 * have a null, which is precisely the population the interstitial is for.
 *
 * `down()` drops the columns and their data with them. That is the accepted
 * cost of an additive migration being reverted; it is written down here so
 * that nobody discovers it by running it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->json('onboarding_intents')->nullable();
            $table->timestamp('welcome_seen_at')->nullable();
        });

        // See the class docblock: without this, every existing business is
        // forced through onboarding at its next login.
        DB::table('businesses')
            ->whereNull('welcome_seen_at')
            ->update(['welcome_seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['onboarding_intents', 'welcome_seen_at']);
        });
    }
};
