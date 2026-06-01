<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        try { Schema::table('warehouses', fn (Blueprint $t) => $t->dropForeign(['location_id'])); } catch (\Throwable) {}
        Schema::table('warehouses', fn (Blueprint $t) => $t->dropColumn('location_id'));
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('user_id')->constrained('locations')->nullOnDelete();
        });
    }
};
