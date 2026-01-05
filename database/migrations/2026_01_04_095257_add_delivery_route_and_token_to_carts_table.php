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
        Schema::table('carts', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_route_id')->nullable()->after('store_id');
            $table->string('checkout_token', 64)->nullable()->unique()->after('guest_token');

            $table->foreign('delivery_route_id')->references('id')->on('delivery_routes')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign(['delivery_route_id']);
            $table->dropColumn(['delivery_route_id', 'checkout_token']);
        });
    }
};
