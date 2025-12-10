<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if column already exists (from earlier migration)
        if (!Schema::hasColumn('customers', 'live_first_status')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->enum('live_first_status', [
                    'not_enrolled',
                    'pending_verification',
                    'verified',
                    'testing',
                    'tested',
                    'approved',
                    'suspended'
                ])->default('not_enrolled')->after('email_verified_at');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('customers', 'live_first_status')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('live_first_status');
            });
        }
    }
};
