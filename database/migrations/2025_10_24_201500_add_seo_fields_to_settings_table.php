<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (!Schema::hasColumn('settings', 'og_title')) {
                $table->string('og_title')->nullable()->after('main_store_id');
            }
            if (!Schema::hasColumn('settings', 'og_description')) {
                $table->text('og_description')->nullable()->after('og_title');
            }
            if (!Schema::hasColumn('settings', 'og_image_path')) {
                $table->string('og_image_path')->nullable()->after('og_description');
            }
            if (!Schema::hasColumn('settings', 'og_url')) {
                $table->string('og_url')->nullable()->after('og_image_path');
            }
            if (!Schema::hasColumn('settings', 'og_type')) {
                $table->string('og_type')->nullable()->after('og_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (Schema::hasColumn('settings', 'og_title')) {
                $table->dropColumn('og_title');
            }
            if (Schema::hasColumn('settings', 'og_description')) {
                $table->dropColumn('og_description');
            }
            if (Schema::hasColumn('settings', 'og_image_path')) {
                $table->dropColumn('og_image_path');
            }
            if (Schema::hasColumn('settings', 'og_url')) {
                $table->dropColumn('og_url');
            }
            if (Schema::hasColumn('settings', 'og_type')) {
                $table->dropColumn('og_type');
            }
        });
    }
};
