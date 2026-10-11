<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a business's settled money is withdrawn to its bank automatically.
 *
 * A business taking payments through Storify has its sales settle into its own
 * connected account at the provider. From there the money has to reach the bank
 * account it registered, and that withdrawal runs on a schedule — see
 * `PlatformPayoutService::sweep()`.
 *
 * The column defaults to **true**, and that is the whole point: it is exactly
 * what the sweep does today, unconditionally, for every business. Adding the
 * switch must not change where a single naira goes, so the default preserves the
 * behaviour rather than asking an admin to opt each business back into it. An
 * admin can turn it off for one business, and the switch only ever *stops*
 * money moving.
 *
 * The backfill below is belt and braces — a column added with a default is
 * already `true` for every existing row on MySQL — but it is written explicitly
 * because the opposite reading (a fresh column arriving as `0` on some other
 * configuration) would silently strand every business's settled balance in its
 * provider account, with nothing on screen to say why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('auto_payout')->default(true)->after('can_connect_own_gateway');
        });

        DB::table('businesses')->update(['auto_payout' => true]);
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('auto_payout');
        });
    }
};
