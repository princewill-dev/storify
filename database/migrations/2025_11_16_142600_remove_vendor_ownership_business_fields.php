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
        Schema::table('vendors', function (Blueprint $table) {
            if (Schema::hasColumn('vendors', 'ownership_type_id')) {
                $table->dropForeign(['ownership_type_id']);
                $table->dropColumn('ownership_type_id');
            }

            if (Schema::hasColumn('vendors', 'business_type_id')) {
                $table->dropForeign(['business_type_id']);
                $table->dropColumn('business_type_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'ownership_type_id')) {
                $table->unsignedBigInteger('ownership_type_id')->nullable()->after('location');
            }

            if (! Schema::hasColumn('vendors', 'business_type_id')) {
                $table->unsignedBigInteger('business_type_id')->nullable()->after('ownership_type_id');
            }
        });
    }
};
