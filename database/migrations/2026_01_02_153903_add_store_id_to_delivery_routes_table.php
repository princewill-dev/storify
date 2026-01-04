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
        // Delete existing delivery routes as they don't have store associations
        DB::table('delivery_routes')->delete();
        
        Schema::table('delivery_routes', function (Blueprint $table) {
            // Add the store_id column if it doesn't exist
            if (!Schema::hasColumn('delivery_routes', 'store_id')) {
                $table->unsignedBigInteger('store_id')->nullable()->after('id');
            }
        });
        
        // Add the foreign key constraint
        Schema::table('delivery_routes', function (Blueprint $table) {
            $table->foreign('store_id')->references('id')->on('stores')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_routes', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
            $table->dropColumn('store_id');
        });
    }
};
