<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Storify keeps from a sale it processes on a business's behalf.
 *
 * Charged to Bachs as `platform_fee` on the direct charge, which is how the
 * money is taken from the business's proceeds rather than invoiced to them
 * afterwards. Bachs settles it into the platform's own balance as a separate
 * record, on the subaccount's settlement schedule.
 *
 * It covers Storify's commission and **not** Bachs' processing fee. That fee is
 * Bachs', is deducted at source, and varies by how the customer chose to pay
 * (cards and transfers are priced differently) — folding it into one number
 * here would make Storify's margin a function of the customer's payment method,
 * and would go stale the next time Bachs changed its prices.
 *
 * Both halves default to zero. Managed charging must not quietly take a cut
 * nobody configured: until an admin sets this on the Payment Methods screen, a
 * processed sale passes to the business in full and Storify earns nothing from
 * it.
 *
 * **The flat half is superseded.** Commission is now a percentage with a
 * per-sale maximum (`2026_10_17_000001_add_platform_commission_max_to_settings_table`).
 * `platform_commission_fixed_kobo` is kept so old rows and a code rollback still
 * read, but nothing computes from it any more.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->decimal('platform_commission_percent', 5, 2)->default(0)->after('billing_gateway');
            $table->unsignedBigInteger('platform_commission_fixed_kobo')->default(0)->after('platform_commission_percent');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['platform_commission_percent', 'platform_commission_fixed_kobo']);
        });
    }
};
