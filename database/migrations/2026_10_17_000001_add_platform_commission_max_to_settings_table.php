<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The most Storify will keep from one processed sale, in kobo.
 *
 * Commission used to be a percentage **plus a flat amount** per sale. It is now
 * a percentage with a ceiling: `fee = min(round_half_up(gross × percent),
 * max)`. The flat amount was the part that went wrong on small sales — a fixed
 * charge can exceed a tiny gross at any percentage, and the per-sale guard then
 * refuses the fee entirely — and it also made the business pay more the more
 * often it sold, rather than the more it sold per sale.
 *
 * **Zero means uncapped, not zero commission.** A fresh install still takes
 * nothing because the percentage defaults to zero; the cap on its own is a
 * ceiling, never a charge.
 *
 * The old `platform_commission_fixed_kobo` column is deliberately retained and
 * deliberately **not** backfilled into this one. A flat fee was charged on every
 * sale; a cap only limits large ones. Translating one into the other would
 * either stop charging the small sales the flat fee used to reach, or invent a
 * ceiling nobody configured. An installation that had a flat fee must be
 * repriced by a person on the Payment Methods screen.
 *
 * Nothing on `transactions` is repriced. `platform_fee_kobo` records what a sale
 * actually bore at the moment it was charged, and stays that way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_commission_max_kobo')
                ->default(0)
                ->after('platform_commission_fixed_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('platform_commission_max_kobo');
        });
    }
};
