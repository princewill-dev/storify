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
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('delivery_route_id')->nullable()->after('vendor_id')->constrained()->nullOnDelete();
            $table->string('delivery_state')->nullable()->after('delivery_route_id');
            $table->string('delivery_area')->nullable()->after('delivery_state');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['delivery_route_id']);
            $table->dropColumn(['delivery_route_id', 'delivery_state', 'delivery_area']);
        });
    }
};
