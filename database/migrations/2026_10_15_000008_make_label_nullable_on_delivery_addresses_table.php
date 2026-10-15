<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A label is optional, so it becomes nullable.
 *
 * The column was `NOT NULL DEFAULT 'Home'`, which only ever held because the
 * single writer — checkout — never set it: every row was an order's delivery
 * copy and got the default. The customer address book has no such luxury. It
 * must be able to record an address the customer did not name, and passing the
 * literal 'Home' on their behalf would put a word in their mouth on a screen
 * they read.
 *
 * Existing rows keep 'Home', which is exactly right: those are snapshots of
 * checkout deliveries, and 'Home' was already what the column said about them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_addresses', function (Blueprint $table) {
            $table->string('label')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Tightening the column back cannot succeed while nulls exist, so
        // unlabelled rows are given the default this migration removed.
        DB::table('delivery_addresses')->whereNull('label')->update(['label' => 'Home']);

        Schema::table('delivery_addresses', function (Blueprint $table) {
            $table->string('label')->nullable(false)->default('Home')->change();
        });
    }
};
