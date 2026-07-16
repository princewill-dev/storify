<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('trial_ends_at')->nullable()->after('force_password_change');
            $table->foreignId('selected_plan_id')->nullable()->after('trial_ends_at')->constrained('subscription_plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['selected_plan_id']);
            $table->dropColumn(['trial_ends_at', 'selected_plan_id']);
        });
    }
};
