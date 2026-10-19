<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The two platform-wide payout policy figures.
     *
     * Both are unsigned and default to zero, which means "no floor" and "no fee"
     * — the behaviour the platform had before this migration. An install that
     * never touches them pays out exactly as it did, and an admin who sets a
     * figure changes the next transfer without a deploy.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedBigInteger('minimum_payout_kobo')
                ->default(0)
                ->after('platform_commission_max_kobo');

            $table->unsignedBigInteger('payout_processing_fee_kobo')
                ->default(0)
                ->after('minimum_payout_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['minimum_payout_kobo', 'payout_processing_fee_kobo']);
        });
    }
};
