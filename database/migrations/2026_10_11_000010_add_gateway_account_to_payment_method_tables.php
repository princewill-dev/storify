<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Bachs connected account a business or store is paid through.
 *
 * A managed connection holds no credentials of its own: the platform's Bachs
 * key makes the charge and the account id in the `X-Account-Id` header is what
 * makes the money the business's rather than Storify's. That id therefore has
 * to be readable back — an incoming webhook names only the account, so finding
 * which tenant it belongs to is a lookup on this column.
 *
 * It lives beside `config` rather than inside it because `config` is a JSON
 * blob: a functional index over JSON is fragile across MySQL versions, and this
 * is the one field in the connection that has to be indexed. Keeping it
 * separate also means a business that is later granted its own keys does not
 * lose its subaccount — `config` is rewritten on a keyed connect, this column
 * is not.
 *
 * Nullable with no default, so every row that exists today reads as "not
 * managed" and the resolver's own-key path is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_payment_method', function (Blueprint $table) {
            $table->string('gateway_account_id', 64)->nullable()->after('config')->index();
        });

        Schema::table('store_payment_method', function (Blueprint $table) {
            $table->string('gateway_account_id', 64)->nullable()->after('config')->index();
        });
    }

    public function down(): void
    {
        Schema::table('business_payment_method', function (Blueprint $table) {
            $table->dropIndex(['gateway_account_id']);
            $table->dropColumn('gateway_account_id');
        });

        Schema::table('store_payment_method', function (Blueprint $table) {
            $table->dropIndex(['gateway_account_id']);
            $table->dropColumn('gateway_account_id');
        });
    }
};
