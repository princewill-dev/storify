<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A plan change that is not in force yet, which is every plan change.
 *
 * Two states, and they are deliberately two columns rather than one, because
 * they mean different things and take effect at different moments:
 *
 *  - **`pending_*`** is an upgrade waiting to be paid for. It was quoted at a
 *    prorated figure against a specific plan, the customer has been sent to the
 *    gateway with it, and it becomes the plan the moment that payment settles.
 *  - **`scheduled_*`** is a downgrade, which costs nothing and therefore waits —
 *    until the end of the period already paid for, so that no paid time is
 *    discarded and nobody is owed a refund.
 *
 * Collapsing them into one column would make "waiting for money" and "waiting
 * for the clock" indistinguishable, and the expiry path has to tell them apart:
 * an abandoned upgrade must not quietly become the plan a renewal is taken on.
 *
 * Both live on the subscription rather than in their own table because each is
 * at most one per subscription — there is no history to keep, only a current
 * intention, and the history of what did change is in the activity log.
 *
 * `payments.payment_type` gains `plan_change` in the same migration because the
 * column is an ENUM: a value it does not list cannot be inserted at all, so the
 * option has to exist before the first prorated charge is recorded. It is a
 * separate kind of payment from a purchase or a renewal — the money is a
 * difference, the period it buys is the remainder of the current one, and
 * activation treats it accordingly — so it needs its own value rather than being
 * filed as `other`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Nullable throughout: the ordinary state of a subscription is that
            // no change is in flight.
            $table->foreignId('pending_plan_id')->nullable()->after('subscription_plan_id')
                ->constrained('subscription_plans')->nullOnDelete();

            // The prorated difference that was quoted, in kobo, held so the
            // checkout prices the pending change and not the plan's list price.
            // Null for a state that is not waiting on money.
            $table->unsignedBigInteger('pending_charge_kobo')->nullable()->after('pending_plan_id');

            $table->timestamp('pending_requested_at')->nullable()->after('pending_charge_kobo');

            $table->foreignId('scheduled_plan_id')->nullable()->after('pending_requested_at')
                ->constrained('subscription_plans')->nullOnDelete();

            $table->timestamp('scheduled_at')->nullable()->after('scheduled_plan_id');
        });

        // Widened rather than replaced, and the existing values keep their
        // meaning, so no row is rewritten and no query changes.
        DB::statement(
            'ALTER TABLE payments MODIFY payment_type '
            ."ENUM('subscription', 'renewal', 'other', 'plan_change') NOT NULL DEFAULT 'subscription'"
        );
    }

    public function down(): void
    {
        // Reverted to `other` before the value is removed from the ENUM: the
        // alternative is a rollback that fails on any database where a plan
        // change has been paid for, which is the moment a rollback is most
        // likely to be wanted.
        DB::table('payments')->where('payment_type', 'plan_change')->update(['payment_type' => 'other']);

        DB::statement(
            'ALTER TABLE payments MODIFY payment_type '
            ."ENUM('subscription', 'renewal', 'other') NOT NULL DEFAULT 'subscription'"
        );

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_plan_id');
            $table->dropColumn('pending_charge_kobo');
            $table->dropColumn('pending_requested_at');
            $table->dropConstrainedForeignId('scheduled_plan_id');
            $table->dropColumn('scheduled_at');
        });
    }
};
