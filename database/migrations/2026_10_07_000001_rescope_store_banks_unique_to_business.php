<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-scope store_banks uniqueness to the business that owns the account.
 *
 * store_id was dropped from this table on 2026_07_12. MySQL removes a dropped
 * column from the indexes it appears in, but keeps the index — and its name —
 * as long as other columns remain. So the unique key narrowed silently from
 * (store_id, account_number, bank_code) to (account_number, bank_code) while
 * still being called store_banks_store_id_account_number_bank_code_unique.
 * That stale name is the only remaining evidence it was ever scoped to
 * anything, and nothing in the migration mentioned the index.
 *
 * The effect was that a bank account became unique across the entire platform
 * rather than per business. Two tenants holding the same account number at the
 * same bank is ordinary — a shared family account, or one owner running two
 * businesses — and the second tenant to register it got a 500 from the insert:
 * PaymentSettingsRepository::bankAccountExists() scopes by business_id, so it
 * correctly found no duplicate of its own and let the request through to a
 * constraint it has no way to satisfy.
 *
 * The replacement key is strictly wider than the one it replaces, so it cannot
 * fail on existing rows: a pair that is unique on its own is still unique once
 * business_id is added ahead of it.
 *
 * business_id is nullable, and MySQL permits repeated NULLs in a unique index,
 * so a row with no business escapes this constraint — exactly as it escaped the
 * old one's store_id.
 */
return new class extends Migration
{
    private const OLD_INDEX = 'store_banks_store_id_account_number_bank_code_unique';

    private const NEW_INDEX = 'store_banks_business_account_unique';

    public function up(): void
    {
        Schema::table('store_banks', function (Blueprint $table) {
            // Dropped by name on purpose: dropUnique(['account_number',
            // 'bank_code']) would look for
            // store_banks_account_number_bank_code_unique, which is not what is
            // actually on the table.
            $table->dropUnique(self::OLD_INDEX);

            $table->unique(['business_id', 'account_number', 'bank_code'], self::NEW_INDEX);
        });
    }

    public function down(): void
    {
        // The constraint has to go first, which is not obvious.
        //
        // business_id is a foreign key, and InnoDB had been serving it with an
        // index of its own. Once the unique index above existed it stopped
        // needing that one — the unique index leads with business_id, so it
        // serves the constraint just as well — and the standalone index was
        // retired. MySQL then refuses to drop the unique index while the
        // constraint still relies on it: "Cannot drop index ... needed in a
        // foreign key constraint" (1553). Re-adding the key recreates the
        // supporting index automatically.
        Schema::table('store_banks', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
        });

        Schema::table('store_banks', function (Blueprint $table) {
            $table->dropUnique(self::NEW_INDEX);

            $table->unique(['account_number', 'bank_code'], self::OLD_INDEX);
        });

        Schema::table('store_banks', function (Blueprint $table) {
            $table->foreign('business_id')->references('id')->on('businesses')->cascadeOnDelete();
        });
    }
};
