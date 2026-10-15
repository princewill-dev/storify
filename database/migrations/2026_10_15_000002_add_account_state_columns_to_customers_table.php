<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two timestamps that give a customer row a history.
 *
 * `registered_at` is what separates a real account from a row checkout
 * fabricated. Before this, both looked identical: the model's `creating` hook
 * stamps `status = active` either way, so nothing distinguished somebody who
 * had chosen a password from somebody who had not.
 *
 * `deletion_requested_at` is a request, not an outcome. Nothing is erased when
 * it is set; it is the queue a business works through.
 *
 * Both are additive and nullable, so this is safe to run before the code that
 * reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('registered_at')->nullable()->after('email_verified_at');
            $table->timestamp('deletion_requested_at')->nullable()->after('registered_at');
        });

        // The management customer list filters on account state, and the
        // deletion queue reads the second column on its own.
        Schema::table('customers', function (Blueprint $table) {
            $table->index('deletion_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['deletion_requested_at']);
            $table->dropColumn(['registered_at', 'deletion_requested_at']);
        });
    }
};
