<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Storify owes a business, and how much of it is safe to pay out.
 *
 * Two questions that used to be answered by the provider are now ours to answer,
 * so they get columns rather than being recomputed from the ledger on demand.
 *
 * **Why business-scoped, when `stores.balance` is per store.** The bank a payout
 * goes to is named once per business (`businesses.payout_bank_id`), so the money
 * has to be pooled at the same grain it leaves at. `stores.balance` cannot be
 * reused for this even though it looks like a wallet: it is credited for sales
 * charged on the business's *own* gateway keys — money that is already sitting in
 * the business's own provider account — and for sales Storify collected. The
 * code does not record which, so summing it would pay out money the business has
 * already been given. It keeps its existing meaning and stops being the payout
 * source; this is the payout source.
 *
 * **Why two buckets.** The provider settles to Storify on its own schedule, so a
 * sale that has been *charged* has not necessarily been *settled*. Paying out of
 * a sale the provider has not funded yet means Storify advances the money from
 * its own balance, and several businesses paying out before settlement overdraw
 * it. A credit therefore lands in `pending_balance` with a settlement date, is
 * promoted to `available_balance` once that date passes, and only
 * `available_balance` is ever swept.
 *
 * **Why signed, when store balances are unsigned.** `Store::debitBalance()`
 * refuses to go below zero, which is right for money the platform holds as an
 * agent. It is wrong here: a refund or a chargeback that arrives *after* the
 * business has been paid out leaves the business owing Storify, and that state
 * has to be representable rather than throwing mid-transaction. The invariant
 * that `pending_balance` never goes negative is enforced in code, where it can
 * produce a readable failure, not by a column type that produces a database
 * error.
 *
 * `balance_updated_at` is the audit pair to `stores.balance_updated_at` — when
 * the figure last moved, so a support call can say when rather than just how
 * much.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->bigInteger('pending_balance')->default(0)->after('payout_entity_type');
            $table->bigInteger('available_balance')->default(0)->after('pending_balance');
            $table->timestamp('balance_updated_at')->nullable()->after('available_balance');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['pending_balance', 'available_balance', 'balance_updated_at']);
        });
    }
};
