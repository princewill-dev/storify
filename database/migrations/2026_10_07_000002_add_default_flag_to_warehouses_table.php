<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the warehouse the platform falls back to when a physical product is
 * saved without one being chosen.
 *
 * Saving a physical product used to require picking a warehouse, which made
 * every new user learn logistics before they could list their first item. The
 * service layer now resolves a fallback instead — this column is how it finds
 * the same one twice rather than leaving a trail of near-identical warehouses.
 *
 * The column is NULLABLE on purpose, and that is the whole trick. MySQL treats
 * every NULL in a unique index as distinct, so `unique(business_id,
 * is_default)` reads as a partial constraint: a business may hold any number
 * of ordinary warehouses (all NULL) but at most one marked `1`. The invariant
 * is therefore:
 *
 *     NULL = an ordinary warehouse
 *        1 = the fallback for that business
 *
 * Never write `is_default = false`. A second ordinary warehouse for the same
 * business would collide with the first on (business_id, 0) and the insert
 * would fail. Leave it NULL.
 *
 * No backfill: existing rows become NULL, which is exactly "ordinary", and no
 * business gains a fallback it did not ask for. It is created on demand, the
 * first time one of its products needs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->boolean('is_default')->nullable()->after('status');

            $table->unique(['business_id', 'is_default'], 'warehouses_business_default_unique');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            // Named explicitly: the default Laravel name here would be
            // warehouses_business_id_is_default_unique.
            $table->dropUnique('warehouses_business_default_unique');

            $table->dropColumn('is_default');
        });
    }
};
