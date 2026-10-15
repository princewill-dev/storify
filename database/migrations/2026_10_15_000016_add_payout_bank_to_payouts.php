<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which of the business's accounts this transfer was made to.
 *
 * `payouts.bank_code` and `account_number` already snapshot the destination —
 * the number and code actually handed to the provider — and they stay the thing
 * the send reads. This adds the row it came from, which the number alone cannot
 * answer: two of a business's accounts could in principle share a bank code, and
 * more to the point, the failure ceiling and the wallet both want to ask "which
 * of my accounts is this" rather than "which account number is this".
 *
 * It is a snapshot, not a lookup. Written in `reserve()` beside the code and
 * number, and never resolved at dispatch — a payout already reserved has to keep
 * going where it was reserved for even if the store changes its bank in the
 * meantime. Reading this column live at dispatch would reintroduce exactly the
 * bug the snapshot columns exist to prevent.
 *
 * **What `store_id` means now.** It was documented as "nullable attribution
 * only". It becomes "the single store this payout was for, when there is exactly
 * one" — null for a payout pooling several stores onto one destination, and null
 * for one paying unattributed money. The per-store breakdown of any payout is
 * already carried by `payout_allocations.store_id`, which is authoritative and
 * needs nothing new.
 *
 * NULL for historical rows is honest: they predate per-destination payouts and
 * nobody knows which account they were meant for beyond the snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->foreignId('payout_bank_id')
                ->nullable()
                ->after('destination_id')
                ->constrained('store_banks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_bank_id');
        });
    }
};
