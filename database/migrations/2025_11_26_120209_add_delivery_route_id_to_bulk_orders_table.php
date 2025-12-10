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
            $table->foreignId('delivery_route_id')->nullable()->after('delivery_address_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bulk_orders', function (Blueprint $table) {
            $table->dropForeign(['delivery_route_id']);
            $table->dropColumn('delivery_route_id');
        });
    }
};
