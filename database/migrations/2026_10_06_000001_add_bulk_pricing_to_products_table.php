<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WS-14 — bulk pricing columns.
 *
 * The product API has validated and mass-assigned `bulk_quantity` /
 * `bulk_price` since before this workstream, and the development database
 * carries both columns — but no migration ever created them, so a freshly
 * migrated database (the test suite) could not persist either field. The
 * guards keep this a no-op on databases that already drifted the columns in,
 * and on any parallel migration that adds them first.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'bulk_quantity') && Schema::hasColumn('products', 'bulk_price')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'bulk_quantity')) {
                $table->unsignedInteger('bulk_quantity')->nullable();
            }

            if (! Schema::hasColumn('products', 'bulk_price')) {
                $table->decimal('bulk_price', 12, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        $columns = array_values(array_filter(
            ['bulk_quantity', 'bulk_price'],
            fn (string $column) => Schema::hasColumn('products', $column),
        ));

        if ($columns === []) {
            return;
        }

        Schema::table('products', function (Blueprint $table) use ($columns) {
            $table->dropColumn($columns);
        });
    }
};
