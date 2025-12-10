<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('family_pack_subscriptions', function (Blueprint $table) {
            $table->unsignedInteger('total_cycles')->nullable()->after('interval_amount');
            $table->unsignedInteger('current_cycle')->nullable()->after('total_cycles');
            $table->unsignedInteger('remaining_cycles')->nullable()->after('current_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('family_pack_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['total_cycles', 'current_cycle', 'remaining_cycles']);
        });
    }
};
