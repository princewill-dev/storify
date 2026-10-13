<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a collected sale came back to the customer.
 *
 * A custody sale credits the business's wallet and, days later, may be given
 * back — refunded by the business, or charged back by the customer's bank. The
 * wallet and both journals are unwound at that point, and this column is what
 * makes that happen exactly once.
 *
 * Two things need the marker, and neither can be served by the fields that
 * already exist:
 *
 * - **The claim.** `settle_at` says a credit happened; it cannot also say the
 *   credit has since been reversed, and clearing it would make the sale
 *   indistinguishable from one that was never credited — which is a state the
 *   promote job actively looks for and would then re-credit.
 * - **The exclusion.** `promotableQuery()` and `awaitingFeeQuery()` are the two
 *   ways a sale can still be credited, and a sale that has been refunded must be
 *   in neither. One predicate is not enough: a sale whose provider fee had not
 *   arrived is waiting in the second and not the first, and a refunded one is in
 *   the first if it was credited before.
 *
 * A timestamp rather than a boolean because the question "when was this taken
 * back" is asked by anybody reconciling a wallet against a bank statement, and a
 * boolean loses it. NULL on every sale that has not come back, which is the
 * overwhelming majority and every row written before this change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('unwound_at')->nullable()->after('promoted_at');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('unwound_at');
        });
    }
};
