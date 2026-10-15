<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which bucket a sale's money was credited to.
 *
 * This is the column that makes per-store wallets safe, and it exists because
 * the store cannot be re-derived reliably later.
 *
 * A sale's store is derived — `PayoutAllocationService::storeIdFor()` reaches it
 * through the order, or failing that the invoice, and returns null for a managed
 * POS till leg that has neither. Deriving it twice gives the same answer twice
 * *only if nothing changed in between*. An order reassigned to another store, or
 * a store soft-deleted, between the credit and the settlement that promotes it
 * would promote out of the business bucket while the money sat on a store — and
 * the store's pending balance would be stranded for ever with nothing to explain
 * why.
 *
 * So the bucket is decided once, at credit, and recorded. Promote, refund and
 * unwind read it back rather than asking the question again. The same reasoning
 * as `payouts.bank_code` / `account_number`, which snapshot the destination onto
 * the row instead of resolving the business's current bank at send time.
 *
 * **NULL means the business bucket**, and that is not a gap to backfill. Every
 * sale credited before this column existed has its money on the business, which
 * is precisely what NULL says, so a later promote or refund resolves correctly
 * with no backfill at all. Writing the derived store onto those rows would be
 * claiming their money lives on a store when it does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('custody_store_id')
                ->nullable()
                ->after('promoted_at')
                ->constrained('stores')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custody_store_id');
        });
    }
};
