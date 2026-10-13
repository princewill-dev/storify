<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every attempt to move money out of Storify's balance to a business.
 *
 * Payout history used to live in a JSON array inside the connection row's
 * `config`, capped at twenty entries. That was proportionate when payouts were a
 * fallback and the provider was the party actually settling. It is not
 * proportionate now: a payout becomes the *only* way a business receives money,
 * so it needs to be queryable ("what has this business been paid, what failed
 * this morning, what is still in flight"), reconcilable against the provider, and
 * immune to the silent truncation a capped array gives you.
 *
 * **The row is reserved before the provider is called.** That ordering is the
 * point of the table. The old idempotency key was a hash of account, destination,
 * amount and date, which was safe only because the provider balance emptied
 * itself — two identical payouts could not legitimately happen. A wallet does not
 * empty itself, so two genuine payouts of the same amount to the same business on
 * the same day are ordinary, and that key would have silently dropped one. Here
 * `reference` is generated first and uniqueness is enforced by the database: a
 * crash-and-retry reuses the reserved row, and a genuine second payout gets a
 * second one.
 *
 * **The destination is snapshotted.** `account_number`, `bank_code` and
 * `account_name` are copied onto the row rather than read through the business at
 * display time. A business can change its bank, and a payout that has already left
 * must still say where it went.
 *
 * `store_id` is nullable attribution only — the money pools at business level
 * because that is the grain the bank is named at — and is kept so a payout can be
 * attributed back to a store's takings after the fact.
 *
 * Statuses: `pending` (row reserved, not yet sent), `processing` (provider has
 * accepted it), `paid` (provider confirmed), `failed` (refused or returned),
 * `reversed` (returned *after* being paid), `cancelled` (abandoned before send).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();

            $table->string('provider', 32);
            // The provider's own destination handle (`pd_…`, `RCP_…`). Nullable:
            // it is created on demand, so the row exists before it does.
            $table->string('destination_id', 64)->nullable();

            $table->string('bank_code', 16)->nullable();
            $table->string('account_number', 32);
            $table->string('account_name', 128)->nullable();

            // Signed for the same reason the wallet is: a reversal can leave a
            // payout in debt, and that must be recordable rather than fatal.
            $table->bigInteger('amount_kobo');
            $table->string('currency', 8)->default('NGN');

            $table->string('status', 24)->default('pending');
            // Both our public handle and the provider's `Idempotency-Key`. One
            // column, not two: they are the same string, and a second column
            // holding a copy is a second thing that can drift out of step. A
            // retry whose predecessor's fate is unknown reuses this row — same
            // reference, same key, provider de-duplicates — rather than opening
            // a second row that would debit the wallet twice.
            $table->string('reference', 64)->unique();
            $table->string('provider_payout_id', 64)->nullable();

            $table->string('failure_code', 64)->nullable();
            $table->string('failure_reason', 255)->nullable();

            // Null when the sweep asked for it and a user id when someone pressed
            // the button — the only way to tell an automated payout from a manual
            // one when reviewing it later.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->json('meta')->nullable();

            $table->timestamps();

            // The console asks "what is owed to and owed by this business".
            $table->index(['business_id', 'status', 'created_at']);
            // Reconciliation: find a row from anything the provider tells us.
            $table->index(['provider', 'provider_payout_id']);
            // The sweeper's stuck-row scan.
            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payouts');
    }
};
