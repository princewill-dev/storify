<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('stores')) return;
        Schema::table('stores', function (Blueprint $table) {
            if (!Schema::hasColumn('stores', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('stores')) return;
        Schema::table('stores', function (Blueprint $table) {
            if (Schema::hasColumn('stores', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};

