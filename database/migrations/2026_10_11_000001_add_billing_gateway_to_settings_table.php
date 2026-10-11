<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which gateway Storify bills its own subscriptions through.
 *
 * A column on the settings singleton rather than a new table: it is one value
 * for the whole platform, chosen by an admin on the Payment Methods screen, and
 * every other platform-wide choice already lives here.
 *
 * Defaulted to `paystack` because that is what platform billing has always
 * been — an existing deployment that never touches this row keeps charging
 * exactly as it did before the column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('billing_gateway', 32)->default('paystack')->after('trial_days');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('billing_gateway');
        });
    }
};
