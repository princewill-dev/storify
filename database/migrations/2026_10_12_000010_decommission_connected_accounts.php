<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The last of S5: the account machinery's data goes, and collect-and-pay-out
 * stops being something a business is opted into.
 *
 * ## Three columns that no longer have a reader
 *
 * `business_payment_method.gateway_account_id` and its twin on
 * `store_payment_method` were the id of the business's own account at the
 * provider — the subaccount, the connected account — and the whole point of a
 * charge naming one was that the provider settled the business directly. Nothing
 * charges that way any more, so nothing writes one, and nothing reads one: the
 * `config` blob's `managed` block it was paired with has gone the same way. They
 * are dropped rather than left nullable-and-ignored because a column nothing
 * reads is a column the next person writes to, and a stale account id on a
 * payment row is exactly the kind of thing a reader would take for a live one.
 *
 * `businesses.payout_entity_type` was the other half of opening that account —
 * company or individual, asked once and immutable, because the provider decided
 * which documents to demand from it. No account is opened, so the question is not
 * asked and the answer is not stored.
 *
 * ## The flag flips from opt-in to on
 *
 * `custody_enabled` was built as a per-business pilot switch, defaulted false,
 * precisely so S1–S4 could ship to a live system and change nothing. There is no
 * longer a rail for a business *not* under custody to be on — a charge either
 * goes through the business's own keys through the ordinary path, or Storify
 * collects it, and there is no per-business account left to settle it directly —
 * so "off" has stopped meaning "as it was" and started meaning "this business
 * cannot be collected for when the admin enforces a processor".
 *
 * Existing rows are therefore backfilled **true**, and the default changes with
 * them. Both halves are needed and they are not the same statement: the backfill
 * says what the rows that already exist now are, and the default says what a
 * business registered tomorrow is. Leaving the default false would have made
 * every new business invisible to `custody:promote` and `custody:payouts` — a
 * business collecting money Storify never pays out, which is the exact failure
 * the collect-and-pay-out model exists to prevent.
 *
 * `custody_enabled_at` is stamped for the backfilled rows rather than left null,
 * so the timestamp keeps meaning "when this business started being collected for"
 * instead of "when somebody happened to click".
 *
 * ## Rollback
 *
 * `down()` restores the three columns and the false default, but it cannot undo
 * the backfill: it has no way to tell a row that was defaulted from one an
 * operator deliberately flipped, since before this migration every row was the
 * former. That is stated rather than papered over — the columns come back empty,
 * and re-running `custody:enable` per business is what restores the flags.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Stamped before the default moves, so every row this migration is
        // responsible for carries a date and no row flipped later can be
        // confused with one of them.
        DB::table('businesses')
            ->where('custody_enabled', false)
            ->update([
                'custody_enabled' => true,
                'custody_enabled_at' => now(),
            ]);

        Schema::table('businesses', function (Blueprint $table) {
            // `change()` rather than a drop-and-recreate: the rows and the index
            // on `(custody_enabled, available_balance)` stay exactly as they are.
            $table->boolean('custody_enabled')->default(true)->change();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('payout_entity_type');
        });

        // The index goes explicitly rather than riding along with the column.
        // MySQL would drop it either way, but only for a column that carries one
        // — and naming it here is what makes the `down()` below able to recreate
        // the pair exactly, rather than recreating a column that is unindexed on
        // one table and indexed on the other.
        Schema::table('business_payment_method', function (Blueprint $table) {
            $table->dropIndex(['gateway_account_id']);
            $table->dropColumn('gateway_account_id');
        });

        Schema::table('store_payment_method', function (Blueprint $table) {
            $table->dropIndex(['gateway_account_id']);
            $table->dropColumn('gateway_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('payout_entity_type', 20)->nullable()->after('payout_bank_id');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('custody_enabled')->default(false)->change();
        });

        Schema::table('business_payment_method', function (Blueprint $table) {
            $table->string('gateway_account_id', 64)->nullable()->after('config')->index();
        });

        Schema::table('store_payment_method', function (Blueprint $table) {
            $table->string('gateway_account_id', 64)->nullable()->after('config')->index();
        });
    }
};
