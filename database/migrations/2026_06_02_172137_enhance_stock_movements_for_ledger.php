<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('business_id')->nullable()->after('id')->constrained('businesses')->nullOnDelete();
            $table->foreignId('stock_location_id')->nullable()->after('product_variant_id')->constrained('stock_locations')->nullOnDelete();
            $table->unsignedBigInteger('balance_before')->nullable()->after('quantity');
            $table->unsignedBigInteger('balance_after')->nullable()->after('balance_before');
            $table->string('idempotency_key')->nullable()->after('performed_by_id');
            $table->unique(['business_id', 'idempotency_key'], 'stock_movements_idempotent');
            $table->index(['product_id', 'stock_location_id']);
            $table->index(['business_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropUnique('stock_movements_idempotent');
            $table->dropIndex(['product_id', 'stock_location_id']);
            $table->dropIndex(['business_id', 'created_at']);
            $table->dropForeign(['business_id']);
            $table->dropForeign(['stock_location_id']);
            $table->dropColumn(['business_id', 'stock_location_id', 'balance_before', 'balance_after', 'idempotency_key']);
        });
    }
};
