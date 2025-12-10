<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bulk_orders', function (Blueprint $table) {
            $table->foreignId('delivery_address_id')->nullable()->after('store_id')->constrained();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulk_orders', function (Blueprint $table) {
            $table->dropForeign(['delivery_address_id']);
            $table->dropColumn('delivery_address_id');
        });
    }
};
