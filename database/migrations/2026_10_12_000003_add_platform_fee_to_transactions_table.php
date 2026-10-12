<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storify's own cut of a managed sale, as a column rather than a metadata key.
 *
 * It has been written to `metadata.platform_fee_kobo` since the split-at-source
 * model shipped, which was fine while the only things reading it were the two
 * ledger postings — both of which run at settlement, on the row in hand. It
 * stopped being fine once a business needed to see it: `net_kobo` cannot be
 * computed without it, and a value reachable only by decoding a JSON blob is a
 * value no query can total, no report can group, and no backfill can find.
 *
 * Nullable, because the column is meaningless for a sale on the business's own
 * keys — there is no commission on those, and a zero would claim there was one.
 *
 * `metadata.platform_fee_kobo` keeps being written. It is the historical record
 * of what was charged at the time, and the only source this column can be
 * backfilled from for rows that predate it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_fee_kobo')->nullable()->after('net_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('platform_fee_kobo');
        });
    }
};
