<?php

use App\Models\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who paid the payment provider's own processing fee on this sale.
 *
 * The fee was always the business's: the provider deducted it from what the
 * business was owed, and the business saw it added together with Storify's
 * commission under one line called "Processing fee". That is now Storify's cost
 * of doing business — Storify is merchant of record and charges on its own
 * credentials, so the provider takes its fee out of Storify's balance, not the
 * business's.
 *
 * ## Why a column and not a date
 *
 * The rule changed on a Tuesday, so the tempting record of it is a cutover
 * timestamp or a config value. Both are re-read at run time, which means every
 * historical row would be re-derived under the new arithmetic the moment
 * somebody re-ran a repair or backfill command — silently raising what businesses
 * were paid on sales that were settled and reported under the old rule.
 *
 * So the fact lives on the row, like `custody_store_id` and `payment_mode`
 * before it. A row carries its own rule for ever, and no re-run can change it.
 *
 * ## Why `business` is the default and needs no backfill
 *
 * Every row written before this column existed bore its provider's fee — that is
 * what the column says, so the default is not a placeholder standing in for
 * "unknown", it is the true answer for every one of them. MySQL fills them at
 * ALTER time, so there is no pass to run, no `updated_at` churn, and no window
 * in which a row is neither. `NULL` also reads as `business` in the model
 * ({@see Transaction::providerFeeBearer()}), the fail-safe direction:
 * an unrefreshed row preserves history rather than re-ruling on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('provider_fee_bearer', 16)
                ->default('business')
                ->after('platform_fee_kobo');
        });

        // Belt and braces, and normally a no-op: the column default above has
        // already filled every existing row by the time this runs. It is here so
        // the migration states its own intent in a statement rather than leaving
        // it implied by a schema attribute — and so a row that somehow escaped
        // the default is claimed for the old rule rather than left unruled.
        DB::table('transactions')
            ->whereNull('provider_fee_bearer')
            ->update(['provider_fee_bearer' => 'business']);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('provider_fee_bearer');
        });
    }
};
