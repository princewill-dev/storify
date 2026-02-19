<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->boolean('is_trial')->default(false)->after('is_default');
            $table->unsignedInteger('trial_days')->nullable()->after('is_trial');
            $table->unsignedInteger('sort_order')->default(0)->after('features');
        });

        // Change interval from enum to varchar to support daily/weekly
        DB::statement("ALTER TABLE subscription_plans MODIFY COLUMN `interval` VARCHAR(20) NOT NULL DEFAULT 'yearly'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE subscription_plans MODIFY COLUMN `interval` ENUM('monthly','yearly') NOT NULL DEFAULT 'yearly'");

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropColumn(['is_trial', 'trial_days', 'sort_order']);
        });
    }
};
