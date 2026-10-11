<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every connected-account delivery the provider has already sent us.
 *
 * Bachs delivers **at least once** and retries a failed attempt up to five
 * times over roughly eighty minutes, so the same event can arrive twice — once
 * because the first response was slow, once because a retry that had already
 * been queued still landed. The payment path tolerates that already (settlement
 * is guarded on the transaction's own status), but not everything on the
 * connect endpoint does: a repeated `payout.paid` would post a second
 * withdrawal to the ledger, and a repeated `account.updated` is wasted work at
 * best.
 *
 * So each delivery is claimed here by the provider's own `evt_…` id before
 * anything is done with it. The claim is written first and **deleted** if the
 * work throws, which is what keeps a genuine failure retryable: Bachs' next
 * attempt finds no claim and processes normally, rather than being dropped as a
 * duplicate of an event that never actually succeeded.
 *
 * `status` is the disposition the catalogue gave the event — `settled`,
 * `payout`, `account`, `noted` — kept so a delivery can be explained after the
 * fact without replaying it. Never a payload: webhook bodies carry customer
 * detail and, for the platform's own events, more than we have any use for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connect_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            // The provider's `evt_…`. Unique, because a retry is the same event
            // and must not be processed twice — the whole reason this table
            // exists.
            $table->string('event_id', 64)->unique();
            $table->string('event_type', 64);
            $table->string('account_id', 64)->nullable()->index();
            $table->string('status', 32);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            // The console asks "what arrived for this account lately", and the
            // account is the only handle a delivery always carries.
            $table->index(['account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connect_webhook_events');
    }
};
