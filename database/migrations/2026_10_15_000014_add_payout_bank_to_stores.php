<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bank a store's takings are paid into, when it is not the business's.
 *
 * Shaped exactly like `businesses.payout_bank_id`, added by
 * `2026_10_12_000002_add_payout_bank_to_businesses.php`, and for the same
 * reasons: a nullable pointer to one of the business's own `store_banks` rows,
 * rather than a reading of `store_banks.is_primary`. That flag drives bank
 * transfer and reconciliation and is settable by a business that never intended
 * to receive payouts there; a destination chosen by that flag would be a
 * destination nobody chose.
 *
 * **Why not the `store_bank` pivot.** That table already exists and already
 * links a store to an account — it means *"this storefront offers this account
 * for manual transfer"*, and it is read on the checkout path to decide what a
 * customer is shown. Pointing at it for payouts would give one row two
 * meanings, which is the same mistake the payout column was separated from
 * `is_primary` to avoid.
 *
 * NULL is not "unassigned yet" as a defect — it is the ordinary state, and it
 * means the store pays out to the business default. Every existing store is
 * NULL and stays NULL: no code path has ever written a store-scoped
 * destination, because `PaymentGatewayService::setPayoutBank()` refused one.
 *
 * `nullOnDelete` because a deleted bank account must not leave a store pointing
 * at a row that is gone; the store falls back to the business default, which is
 * the answer that needs no repair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('payout_bank_id')
                ->nullable()
                ->after('balance_updated_at')
                ->constrained('store_banks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_bank_id');
        });
    }
};
