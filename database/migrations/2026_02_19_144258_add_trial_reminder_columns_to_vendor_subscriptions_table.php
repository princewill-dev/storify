<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_subscriptions', function (Blueprint $table) {
            $table->timestamp('trial_reminder_day5_sent_at')->nullable()->after('metadata');
            $table->timestamp('trial_reminder_day6_sent_at')->nullable()->after('trial_reminder_day5_sent_at');
            $table->timestamp('trial_reminder_day7_sent_at')->nullable()->after('trial_reminder_day6_sent_at');
            $table->timestamp('trial_expired_sent_at')->nullable()->after('trial_reminder_day7_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('vendor_subscriptions', function (Blueprint $table) {
            $table->dropColumn([
                'trial_reminder_day5_sent_at',
                'trial_reminder_day6_sent_at',
                'trial_reminder_day7_sent_at',
                'trial_expired_sent_at',
            ]);
        });
    }
};
