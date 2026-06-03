<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('status')->default('active')->after('is_active');
        });

        DB::statement("UPDATE warehouses SET status = CASE WHEN is_active = 1 THEN 'active' ELSE 'inactive' END");

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('status');
        });

        DB::statement("UPDATE warehouses SET is_active = CASE WHEN status = 'active' THEN 1 ELSE 0 END");

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
