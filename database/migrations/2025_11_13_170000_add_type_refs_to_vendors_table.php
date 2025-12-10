<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->foreignId('ownership_type_id')->nullable()->after('kyc_status')->constrained('ownership_types')->nullOnDelete();
            $table->foreignId('business_type_id')->nullable()->after('ownership_type_id')->constrained('business_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropForeign(['ownership_type_id']);
            $table->dropForeign(['business_type_id']);
            $table->dropColumn(['ownership_type_id', 'business_type_id']);
        });
    }
};
