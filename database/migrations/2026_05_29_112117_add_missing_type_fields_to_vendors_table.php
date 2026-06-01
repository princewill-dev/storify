<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('vendors', 'ownership_type_id')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->foreignId('ownership_type_id')->nullable()->after('location')->constrained('ownership_types')->nullOnDelete();
            });
        }
        if (!Schema::hasColumn('vendors', 'business_type_id')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->foreignId('business_type_id')->nullable()->after('ownership_type_id')->constrained('business_types')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (Schema::hasColumn('vendors', 'business_type_id')) {
                $table->dropForeign(['business_type_id']);
                $table->dropColumn('business_type_id');
            }
            if (Schema::hasColumn('vendors', 'ownership_type_id')) {
                $table->dropForeign(['ownership_type_id']);
                $table->dropColumn('ownership_type_id');
            }
        });
    }
};
