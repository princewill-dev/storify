<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_addresses', function (Blueprint $table) {
            $table->foreignId('delivery_route_id')
                ->after('customer_id')
                ->nullable()
                ->constrained('delivery_routes')
                ->nullOnDelete();
            $table->string('delivery_state')->after('state')->nullable();
            $table->string('delivery_area')->after('city')->nullable();
            $table->unsignedInteger('delivery_fee')->after('map_link')->nullable();
            $table->unsignedSmallInteger('delivery_days')->after('delivery_fee')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_addresses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_route_id');
            $table->dropColumn([
                'delivery_state',
                'delivery_area',
                'delivery_fee',
                'delivery_days',
            ]);
        });
    }
};
