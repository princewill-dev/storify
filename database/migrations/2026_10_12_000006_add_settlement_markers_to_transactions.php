<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a collected sale becomes money Storify can actually hand over.
 *
 * A managed sale credits the business's wallet the moment it is confirmed, but
 * the provider has not necessarily settled that money to Storify yet. Without a
 * per-sale record of that gap the promote job has nothing to work from and the
 * only options are "pay out immediately and hope" or "never pay out". These two
 * columns are that record.
 *
 * `settle_at` is when the provider is expected to have funded us — written when
 * the credit is posted, from the provider's own settlement window. It is a
 * timestamp rather than a boolean because the window differs per provider and
 * per rail, and a provider that changes its schedule should not need a schema
 * change to follow.
 *
 * `promoted_at` is when the credit was moved from the business's
 * `pending_balance` into its `available_balance`. It is what makes the promote
 * job idempotent: a sale is promoted once, and re-running the job over a window
 * that already passed moves nothing. NULL on every row that has not been
 * promoted, which is also every row that is not a managed credit — own-key
 * sales never enter the wallet at all.
 *
 * Both are nullable and neither is backfilled. Rows written before this change
 * were split at source, so they were never Storify's money to hold and have no
 * settlement of ours to record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('settle_at')->nullable()->after('paid_at');
            $table->timestamp('promoted_at')->nullable()->after('settle_at');

            // The promote job's only query: everything due that has not already
            // been promoted. Leading with `promoted_at` keeps the scan off the
            // rows — the majority — that are already done.
            $table->index(['promoted_at', 'settle_at']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['promoted_at', 'settle_at']);
            $table->dropColumn(['settle_at', 'promoted_at']);
        });
    }
};
