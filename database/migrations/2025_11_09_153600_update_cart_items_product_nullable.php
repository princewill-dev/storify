<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $constraintName = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->select('CONSTRAINT_NAME')
            ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
            ->where('TABLE_NAME', 'cart_items')
            ->where('COLUMN_NAME', 'product_id')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->value('CONSTRAINT_NAME');

        Schema::table('cart_items', function (Blueprint $table) use ($constraintName) {
            if ($constraintName) {
                $table->dropForeign($constraintName);
            }
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->change();
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
        });
    }
};
