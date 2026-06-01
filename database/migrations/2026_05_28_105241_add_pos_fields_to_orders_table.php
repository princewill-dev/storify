<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('staff_id')->nullable()->after('vendor_id')->constrained('users')->nullOnDelete();
            $table->foreignId('pos_session_id')->nullable()->after('staff_id')->constrained('pos_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['staff_id']);
            $table->dropForeign(['pos_session_id']);
            $table->dropColumn(['staff_id', 'pos_session_id']);
        });
    }
};
