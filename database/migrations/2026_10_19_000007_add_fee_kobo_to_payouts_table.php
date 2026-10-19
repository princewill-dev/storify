<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The processing fee withheld from this particular transfer.
     *
     * A snapshot, not a reference to the setting: the amount a business agreed
     * to when the payout was reserved is the amount it pays, even if an admin
     * changes the fee while the transfer is in flight. `amount_kobo` remains the
     * gross amount debited from the wallet and the sum of the allocations; the
     * net sent to the bank is `amount_kobo - fee_kobo` and is derived, never
     * stored, so the three figures cannot drift apart.
     */
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->unsignedBigInteger('fee_kobo')->default(0)->after('amount_kobo');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropColumn('fee_kobo');
        });
    }
};
