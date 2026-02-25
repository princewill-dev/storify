<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // stock_quantity = the initial/max stock the vendor set.
            // quantity = the remaining stock (decremented by orders).
            // Nullable so existing products don't error; backfilled below.
            $table->unsignedInteger('stock_quantity')->nullable()->after('quantity');
        });

        // Backfill: for existing rows, set stock_quantity = quantity
        // (we don't have history so we use current quantity as a sensible default).
        DB::statement('UPDATE products SET stock_quantity = quantity WHERE stock_quantity IS NULL');
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('stock_quantity');
        });
    }
};
