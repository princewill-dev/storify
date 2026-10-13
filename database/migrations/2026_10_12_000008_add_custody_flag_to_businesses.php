<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which businesses Storify collects for and pays out to, one at a time.
 *
 * **Per business, not one global switch, and that is the whole design.** The
 * staging plan calls for flipping a single pilot business and leaving every other
 * one exactly as it is — and flipping means three things changing together, in
 * lockstep, for that business and no other:
 *
 * 1. its charges stop naming the business's own account at the provider, so the
 *    money lands with Storify instead of at the business;
 * 2. a settled sale therefore starts crediting the business's wallet, because
 *    from that moment Storify is holding money it owes them;
 * 3. the payout sweep starts owing that business a payment, from that wallet.
 *
 * A global boolean cannot express that. Turning it on would move every business
 * at once, and turning it off would roll every business back at once — so the
 * pilot the plan is built around would not be possible, and the rollback for one
 * unhappy business would be a rollback for all of them.
 *
 * Defaults false, and nothing in this change sets it true. A business with this
 * flag off is untouched: it charges through its own account exactly as today,
 * nothing credits its wallet, and the sweep skips it. That is what makes S1 and
 * S2 shippable to a live system — the code exists and does nothing.
 *
 * Not a `config()` value because it is per-tenant data an admin has to change
 * without a deploy, and because the sweep filters on it in SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('custody_enabled')->default(false)->after('auto_payout');

            // When it was flipped, so a business that is unexpectedly holding
            // Storify's money can be traced to the day the decision was made —
            // the boolean alone would only ever say "yes".
            $table->timestamp('custody_enabled_at')->nullable()->after('custody_enabled');

            // The sweep's own predicate: enabled businesses with money to pay.
            $table->index(['custody_enabled', 'available_balance']);
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropIndex(['custody_enabled', 'available_balance']);
            $table->dropColumn(['custody_enabled', 'custody_enabled_at']);
        });
    }
};
