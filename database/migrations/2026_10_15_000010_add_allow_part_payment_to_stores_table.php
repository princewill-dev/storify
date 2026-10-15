<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this storefront lets a customer pay part of an order and settle the
 * rest later.
 *
 * Defaults to false, so this is an opt-in: every existing store loses the
 * part-payment option at checkout until its owner turns it on. That is only
 * safe because paying the *outstanding balance* stays allowed either way — an
 * order already part-paid is always completable, which is what stops a store
 * that never opts in from stranding customers who have money on the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->boolean('allow_part_payment')->default(false)->after('has_website');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('allow_part_payment');
        });
    }
};
