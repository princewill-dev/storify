<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one processor Storify takes every business's customer money through — or
 * NULL, meaning "off" and every business keeps playing by the old rules.
 *
 * A column rather than a table, for the same reason `billing_gateway` is one:
 * it is a single platform-wide choice an admin makes, and every other such
 * choice already lives on this singleton row.
 *
 * **NULL is the default, and that is the whole point.** An existing deployment
 * that never touches this row behaves exactly as it did before the column
 * existed — businesses charge through whatever they have connected, and nothing
 * about the money path changes. `SystemProcessor` reads NULL as "off"; it is
 * only when an admin deliberately names a provider that enforcement begins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('system_payment_processor', 32)->nullable()->default(null)->after('billing_gateway');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('system_payment_processor');
        });
    }
};
