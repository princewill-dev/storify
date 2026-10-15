<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope storefront OTPs to a business.
 *
 * A customer account belongs to one business, and one email may hold an
 * account at several. An OTP keyed on the email alone therefore verifies for
 * every business that email trades with — which, paired with a reset lookup
 * that also ignored the business, allowed a code mailed for one tenant to
 * reset another tenant's account.
 *
 * The column is nullable on purpose and every existing caller keeps passing
 * nothing, so management and admin OTP rows are written and read exactly as
 * before. Only the storefront passes a business id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            $table->foreignId('business_id')
                ->nullable()
                ->after('identifier')
                ->constrained()
                ->nullOnDelete();
        });

        // The old index cannot serve the scoped lookup; this one can, and it
        // still leads with identifier so an unscoped lookup is unaffected.
        Schema::table('otps', function (Blueprint $table) {
            $table->dropIndex(['identifier', 'type', 'is_verified']);
            $table->index(['identifier', 'type', 'business_id', 'is_verified'], 'otps_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('otps', function (Blueprint $table) {
            $table->dropIndex('otps_lookup_index');
            $table->index(['identifier', 'type', 'is_verified']);
        });

        Schema::table('otps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_id');
        });
    }
};
