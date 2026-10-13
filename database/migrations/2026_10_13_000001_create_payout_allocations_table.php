<?php

use App\Services\Payments\Custody\CustodyService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which sales each payout actually paid.
 *
 * A payout sends **one** figure — the business's whole payable balance — so the
 * `payouts` row on its own cannot answer "what did this ₦47,500 consist of", and
 * neither can a single `transactions.payout_id`. A pointer is not enough because
 * one sale can be split across two payouts by design: a refund taken *after* a
 * payout deliberately drives `available_balance` negative
 * ({@see CustodyService::reverseCollectedSale()}),
 * and later sales recover it. Sale A net 100 paid out and refunded, sale B net 150
 * maturing next, and the sweep sends 50 — which is the first 50 of B. A sale
 * pointer would have to call that 50 "A" or "B", and both answers are wrong.
 *
 * So the attribution is a table, and the load-bearing column is `amount_kobo`
 * rather than the pair of ids: it is what lets one sale appear in two payouts, and
 * what makes a paid payout's rows sum to `payouts.amount_kobo` **exactly**.
 *
 * ## The invariant, and why the statuses are named after the payout
 *
 * > A payout's allocations never exceed its amount, and equal it whenever the
 * > sales behind the money are known.
 *
 * The "whenever known" is not a loophole: a payout is drawn from matured sales, so
 * the rows are the whole of the sum in every state the wallet itself can produce.
 * It is there because the sweep pays the wallet's own figure when the walk comes
 * up short, and the alternative — refusing to pay a business money the wallet says
 * it is owed, because the attribution is thin — is the wrong way round. A
 * shortfall is logged as `custody.payout.allocation_short`.
 *
 * `reserved` and `settled` both still claim the sale's money — the first because
 * the transfer is in flight, the second because it landed — so both are excluded
 * when working out what a *later* payout may draw on. `released` and `reversed`
 * are the two ways the claim goes away, and they never touch the amount already
 * written on the payout row: a released allocation means the money came back to
 * the wallet, not that the payout became a different payout.
 *
 * ## Store attribution is derived, and approximate at the edges
 *
 * `transactions` has no `store_id`; a store comes from the sale's order or its
 * invoice. A managed till leg may have neither, so `store_id` is nullable here and
 * the row is still perfectly usable for everything except a per-store report. It
 * is stored rather than joined at read time because it is read by the store-hold
 * walk (see `PayoutHoldService`), which runs inside the payout transaction and
 * should not be re-deriving a join it already made.
 *
 * Nothing is backfilled. Payouts sent before this table existed have no
 * allocations, which reads as "we do not know which sales it covered" — the true
 * answer — rather than as an invented one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payout_id')->constrained('payouts')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();

            // Nullable: the sale's order or invoice is the only source, and a
            // managed till leg can have neither. Null means "not attributable to
            // one store", which is what a store-scoped report needs to know.
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();

            // Signed like every other money column here, because a reversal of a
            // reversal is not worth a fatal error — though nothing writes a
            // negative one today.
            $table->bigInteger('amount_kobo');
            $table->string('currency', 8)->default('NGN');

            // `reserved` | `settled` | `released` | `reversed`.
            $table->string('status', 24)->default('reserved');

            $table->timestamp('settled_at')->nullable();
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            // One sale cannot be allocated to the same payout twice. This is what
            // makes the allocation re-runnable after a crash: the insert is the
            // claim, so a retry adds nothing rather than doubling a sale's share.
            $table->unique(['payout_id', 'transaction_id'], 'payout_allocations_payout_transaction_unique');

            // The two reads that happen on every sweep: "what has this sale
            // already been allocated" (per transaction, claiming statuses), and
            // "which sales did this payout cover" (per payout).
            $table->index(['transaction_id', 'status'], 'payout_allocations_transaction_status_index');
            $table->index(['payout_id', 'status'], 'payout_allocations_payout_status_index');
            $table->index(['business_id', 'status'], 'payout_allocations_business_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_allocations');
    }
};
