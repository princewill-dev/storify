<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The custody wallet, per store.
 *
 * `2026_10_12_000005_add_business_wallet_columns.php` states the rule this
 * migration follows: *"the bank a payout goes to is named once per business, so
 * the money has to be pooled at the same grain it leaves at."* A store can now
 * name its own payout account, so the grain has changed, and the wallet moves
 * with it. That migration's own docblock is the argument for this one.
 *
 * **Why not reuse `stores.balance`.** Three reasons, and any one is enough.
 *
 *  - It is a *different* money. `stores.balance` is credited for sales charged
 *    on the business's **own gateway keys** — money already sitting in the
 *    business's own provider account — while this is money Storify collected and
 *    is holding. Summing them would pay out money the business has already been
 *    given.
 *  - It is unsigned and refuses to go below zero (`Store::debitBalance()`), which
 *    is right for money held as an agent and wrong here: a refund arriving after
 *    a payout leaves the store owing Storify, and that state has to be
 *    representable rather than throwing mid-transaction.
 *  - `WalletSummaryService::SOURCE_STORE` is read by four resources to decide
 *    which figure a screen shows. Two meanings on one column makes that decision
 *    unanswerable.
 *
 * So a store ends up with two money columns, and the `balance_source` contract
 * still means what it meant.
 *
 * **The business columns stay, and are not a cache.** They become the bucket for
 * money that belongs to the business and to no store — `PayoutAllocationService::storeIdFor()`
 * returns null for a managed POS till leg, which has neither an order nor an
 * invoice to derive a store from, and `WalletSummaryService::attributedByStore()`
 * already refuses to guess at one. That money needs somewhere to live. It also
 * means the backfill moves nothing: whatever is in
 * `businesses.pending_balance`/`available_balance` today is, under this reading,
 * exactly what it has always been — money no store accounts for.
 *
 * Signed, indexed on `available_balance`, and shaped exactly like the business
 * pair so the primitives can be mirrored rather than invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->bigInteger('pending_balance')->default(0)->after('balance');
            $table->bigInteger('available_balance')->default(0)->after('pending_balance');
            $table->timestamp('balance_updated_at')->nullable()->after('available_balance');

            // The sweep scans for stores with something to pay out.
            $table->index('available_balance');
            $table->index(['business_id', 'available_balance']);
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'available_balance']);
            $table->dropIndex(['available_balance']);
            $table->dropColumn(['pending_balance', 'available_balance', 'balance_updated_at']);
        });
    }
};
