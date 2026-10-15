<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Grandfather the customers who have already signed in.
 *
 * Sign-in now requires a verified email, because checkout is account creation
 * and an unverified row must not be able to place an order. Without this,
 * everybody who registered, confirmed nothing and signed in anyway would be
 * asked to verify a code on their next visit.
 *
 * `last_login` is the evidence: a row carrying it has had a successful
 * password check against it, which is proof enough that the mailbox holder is
 * the person signing in. A row that has never been signed into is left alone,
 * and is exactly who the new gate is meant to stop — which is why this ships
 * in the same release as the gate and never after it.
 *
 * Not the same as registering, deliberately: a row checkout fabricated has no
 * `last_login`, and must not be grandfathered.
 */
return new class extends Migration
{
    public function up(): void
    {
        $updated = DB::table('customers')
            ->whereNull('email_verified_at')
            ->whereNotNull('last_login')
            ->update(['email_verified_at' => DB::raw('last_login')]);

        Log::info('customers.grandfathered_email_verification', ['updated' => $updated]);
    }

    /**
     * Deliberately empty.
     *
     * The rows this touched are indistinguishable from rows a customer
     * verified themselves, and un-verifying them on a rollback would lock out
     * people who did nothing wrong.
     */
    public function down(): void {}
};
