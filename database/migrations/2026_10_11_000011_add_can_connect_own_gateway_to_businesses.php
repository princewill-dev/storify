<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a business may connect its own payment gateway keys.
 *
 * The platform's default is that a business's payments are processed for it:
 * the owner registers a payout account and Storify charges on their behalf
 * through its own Bachs account, with the sale landing in that business's
 * connected account. Connecting your own keys is the exception, granted to a
 * business by an admin — an enterprise that already has a merchant account and
 * would rather use it.
 *
 * So the column defaults to false, and that default is the whole policy: every
 * business created from here on is platform-processed until an admin says
 * otherwise.
 *
 * The backfill is what stops that default taking something away. Businesses
 * already charging through their own keys would otherwise wake up unable to
 * charge at all — their connections are still in the database, but the switch
 * that permits them would be off. Every business with a live keyed connection
 * today is granted the ability it is already exercising, so deploying this
 * changes behaviour for nobody. An admin can revoke it afterwards.
 *
 * "Keyed" excludes bank transfer deliberately: its provision is a bank account
 * the business owns, not credentials, and granting on the strength of one would
 * read the manual-transfer rail as a connected gateway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('can_connect_own_gateway')->default(false)->after('status');
        });

        foreach ($this->businessIdsWithOwnKeys() as $businessId) {
            DB::table('businesses')->where('id', $businessId)->update([
                'can_connect_own_gateway' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('can_connect_own_gateway');
        });
    }

    /**
     * Businesses with a credential-bearing connection at either scope.
     *
     * Both tables are read because a store-level connection is a business's
     * connection too — `store_payment_method` carries `business_id` for exactly
     * this kind of query.
     *
     * A connection with an empty `config` is not counted. That shape is how the
     * resolver expresses "assigned, but inherits the business's keys", so a row
     * holding nothing is a store setting, not a credential.
     *
     * @return array<int, int>
     */
    private function businessIdsWithOwnKeys(): array
    {
        // Every column is qualified: both joins bring a `config` of their own
        // into scope, and an unqualified one is ambiguous — which is a
        // `QueryException`, not a wrong answer, so it fails loudly either way.
        //
        // The empty string is excluded with its own predicate rather than as a
        // member of a NOT IN list. Laravel renders `whereNotIn('config', ['', …])`
        // as `config not in (, [], {})`, which is not SQL at all, and a null in
        // the list would be worse: `x not in (null)` is unknown for every row, so
        // the whole backfill would silently match nobody.
        $hasConfig = static fn ($query, string $column) => $query
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->whereNotIn($column, ['[]', '{}']);

        $businessLevel = $hasConfig(
            DB::table('business_payment_method as bpm')
                ->join('payment_methods as pm', 'pm.id', '=', 'bpm.payment_method_id')
                ->where('pm.code', '!=', 'bank_transfer')
                ->select('bpm.business_id'),
            'bpm.config',
        )->pluck('bpm.business_id');

        $storeLevel = $hasConfig(
            DB::table('store_payment_method as spm')
                ->join('payment_methods as pm', 'pm.id', '=', 'spm.payment_method_id')
                ->where('pm.code', '!=', 'bank_transfer')
                ->whereNotNull('spm.business_id')
                ->select('spm.business_id'),
            'spm.config',
        )->pluck('spm.business_id');

        return $businessLevel
            ->merge($storeLevel)
            ->filter()
            ->unique()
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }
};
