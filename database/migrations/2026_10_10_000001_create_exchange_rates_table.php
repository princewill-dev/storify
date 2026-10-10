<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily exchange rates, keyed by currency pair.
 *
 * A row holds **units of `quote` per one `base`**, so the naira row is
 * `base=USD, quote=NGN, rate=1331.267014`. Rows are fetched from one USD-based
 * public API, which is why every pair this app needs is expressible as a ratio
 * of two rows rather than as a row of its own.
 *
 * `rate` is decimal(18,8) rather than a float: it is money-adjacent, and the
 * whole point of persisting it is that the number charged is the number
 * recorded, not a re-derivation that may drift.
 *
 * There is deliberately no foreign key to `currencies`. A rate whose currency
 * was later removed from the catalogue is still a true statement about the
 * world, and deleting it would silently take a USD gateway offline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('base', 3);
            $table->char('quote', 3);
            $table->decimal('rate', 18, 8);
            // When the provider published the rate, not when we fetched it —
            // the two differ when the job runs late, and only this one says
            // whether the number is current.
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['base', 'quote']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
