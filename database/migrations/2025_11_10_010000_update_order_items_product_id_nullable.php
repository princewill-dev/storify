<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'product_id')) {
                $table->dropForeign(['product_id']);
            }
        });

        DB::statement('ALTER TABLE order_items MODIFY product_id BIGINT UNSIGNED NULL');

        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'product_id')) {
                $table->foreign('product_id')
                    ->references('id')
                    ->on('products')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'product_id')) {
                $table->dropForeign(['product_id']);
            }
        });

        DB::statement('ALTER TABLE order_items MODIFY product_id BIGINT UNSIGNED NOT NULL');

        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'product_id')) {
                $table->foreign('product_id')
                    ->references('id')
                    ->on('products')
                    ->cascadeOnDelete();
            }
        });
    }
};
