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
        Schema::table('delivery_addresses', function (Blueprint $table) {
            if (Schema::hasColumn('delivery_addresses', 'country')) {
                $table->dropColumn('country');
            }

            if (Schema::hasColumn('delivery_addresses', 'state')) {
                $table->dropColumn('state');
            }

            if (Schema::hasColumn('delivery_addresses', 'city')) {
                $table->dropColumn('city');
            }

            if (Schema::hasColumn('delivery_addresses', 'delivery_state')) {
                $table->dropColumn('delivery_state');
            }

            if (Schema::hasColumn('delivery_addresses', 'delivery_area')) {
                $table->dropColumn('delivery_area');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_addresses', function (Blueprint $table) {
            if (!Schema::hasColumn('delivery_addresses', 'country')) {
                $table->string('country')->default('Nigeria')->after('map_link');
            }

            if (!Schema::hasColumn('delivery_addresses', 'state')) {
                $table->string('state')->nullable()->after('country');
            }

            if (!Schema::hasColumn('delivery_addresses', 'city')) {
                $table->string('city')->nullable()->after('state');
            }

            if (!Schema::hasColumn('delivery_addresses', 'delivery_state')) {
                $table->string('delivery_state')->nullable()->after('city');
            }

            if (!Schema::hasColumn('delivery_addresses', 'delivery_area')) {
                $table->string('delivery_area')->nullable()->after('delivery_state');
            }
        });
    }
};
