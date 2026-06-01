<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('business_location')->nullable()->after('business_setup_complete');
            $table->string('business_model', 20)->nullable()->after('business_location');
            $table->string('currency', 10)->nullable()->after('business_model');
            $table->string('physical_store_count', 10)->nullable()->after('currency');
            $table->string('store_slug')->nullable()->after('physical_store_count');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['business_location', 'business_model', 'currency', 'physical_store_count', 'store_slug']);
        });
    }
};
