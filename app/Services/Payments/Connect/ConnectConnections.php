<?php

namespace App\Services\Payments\Connect;

use Illuminate\Support\Facades\DB;

/**
 * The connection rows a connected account can live on, and how to read them.
 *
 * A subaccount is recorded the same way on either scope:
 * `business_payment_method` for a business's own payouts and
 * `store_payment_method` for a store that banks separately, each with the
 * provider's `acct_…` in an indexed column. Three services now need to find
 * those rows — the payout service, the account-state recorder and the
 * requirement submitter — and a fourth will eventually. Two of them each
 * holding their own copy of "query two tables and remember which one a row came
 * from" is how a store's money quietly stops being found.
 *
 * A row is returned as a plain object from the query builder rather than as an
 * Eloquent model: these tables are written through `updateOrInsert` elsewhere
 * and carry an encrypted `config`, and handing out a model would invite a
 * `save()` that re-encodes it wrongly. What travels with a row is
 * `source_table` — added here, once — because writing the config back is the
 * one operation that has to know which table it came from.
 *
 * **The provider travels with the row too**, and since the platform can process
 * on a business's behalf on more than one it has to: `acct_…` is Bachs' and
 * `ACCT_…` is Paystack's, they mean different things, and the services that read
 * these rows are not interchangeable between them. Every query here therefore
 * carries `provider` (from `payment_methods`) and every reader that acts on
 * *money* names the provider it acts for. Adding it to the row rather than
 * filtering silently in one method is deliberate: a caller that needs to skip
 * another provider can now see which one it is holding instead of finding an
 * empty list.
 */
final class ConnectConnections
{
    private const SCOPES = [
        'business_payment_method' => 'business_id',
        'store_payment_method' => 'store_id',
    ];

    /**
     * The row a given scope's money belongs to.
     *
     * Store-scoped when a store is named, because that is the account its
     * charges went to and therefore the account its money is in. Falls back to
     * the business's, which is what a store without an account of its own is
     * paid through.
     *
     * `$provider` narrows both to one provider's account. The fallback stays
     * within it — a store with a Paystack subaccount must not be answered with
     * the business's Bachs account just because no Bachs row was found for the
     * store, which is the shape of bug a shared list of account ids invites.
     */
    public function for(int $businessId, ?int $storeId, ?string $provider = null): ?object
    {
        if ($storeId !== null) {
            $row = $this->rowFor('store_payment_method', 'store_id', $storeId, $provider);

            if ($row !== null) {
                return $row;
            }
        }

        return $this->rowFor('business_payment_method', 'business_id', $businessId, $provider);
    }

    /**
     * The row holding this provider account id, or null when it is not ours.
     *
     * The reverse of the account id being on the row: a delivery names an
     * account and nothing else, so this is how a webhook finds its tenant. Not
     * filtered by provider, because the id in hand already is one — and the
     * row's `provider` is what tells the caller which vocabulary to use on it.
     */
    public function forAccount(string $accountId): ?object
    {
        foreach (self::SCOPES as $table => $scope) {
            $row = DB::table($table.' as c')
                ->leftJoin('payment_methods as pm', 'pm.id', '=', 'c.payment_method_id')
                ->where('c.gateway_account_id', $accountId)
                ->first($this->columns($scope));

            if ($row !== null) {
                $row->source_table = $table;

                return $row;
            }
        }

        return null;
    }

    /**
     * Every connection with an account, business-scoped rows first.
     *
     * A store with its own account is a separate row and therefore a separate
     * payout: paying the business's account would leave the store's money where
     * the store cannot see it. Unfiltered by `is_active`, deliberately —
     * switching Storify payments off stops new charges, and the money already
     * sitting in the account still has to reach the bank.
     *
     * @return array<int, object>
     */
    public function all(?string $provider = null): array
    {
        $rows = [];

        foreach (self::SCOPES as $table => $scope) {
            foreach (DB::table($table.' as c')
                ->leftJoin('payment_methods as pm', 'pm.id', '=', 'c.payment_method_id')
                ->whereNotNull('c.gateway_account_id')
                ->where('c.gateway_account_id', '!=', '')
                ->when($provider !== null, fn ($query) => $query->where('pm.code', $provider))
                ->get($this->columns($scope)) as $row) {
                $row->source_table = $table;
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Every account one business holds, business row first then its stores.
     *
     * What the admin console needs and `for()` cannot answer: a business whose
     * stores each bank separately has several accounts, and the console has to
     * offer a choice between them rather than showing whichever one the fallback
     * rule happens to land on. Without this the second store's money is
     * unreachable from the screen even though the API can pay it.
     *
     * Ordered rather than incidental, because the console renders this as a list
     * and an order that shifted between requests would make the selected row
     * jump under a reader's cursor.
     *
     * @return array<int, object>
     */
    public function forBusiness(int $businessId): array
    {
        $rows = [];

        foreach (self::SCOPES as $table => $scope) {
            foreach (DB::table($table.' as c')
                ->leftJoin('payment_methods as pm', 'pm.id', '=', 'c.payment_method_id')
                ->where('c.business_id', $businessId)
                ->whereNotNull('c.gateway_account_id')
                ->where('c.gateway_account_id', '!=', '')
                // `is_active` is here because the console reports a paused
                // account as paused rather than as one with no money: switching
                // payments off stops new charges and does not stop a payout, and
                // without this the two would be indistinguishable from an id.
                ->orderBy('c.'.$scope)
                ->get([...$this->columns($scope), 'c.is_active']) as $row) {
                $row->source_table = $table;
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The tenant a row belongs to, in the shape the rest of the feature uses.
     *
     * @return array{business_id: int, store_id: int|null}
     */
    public function scopeOf(object $connection): array
    {
        return [
            'business_id' => (int) $connection->business_id,
            'store_id' => $connection->source_table === 'store_payment_method'
                ? (int) $connection->scope_id
                : null,
        ];
    }

    /**
     * The row's stored config, left encrypted.
     *
     * Deliberately not run through the cipher: what these services touch —
     * `managed`, `payouts`, `disputes` — is non-secret operational state, and
     * the secret fields that may sit beside it on a business that has since
     * connected its own keys are written back exactly as they were read.
     * Decrypting to re-encrypt would be a round trip whose only possible
     * outcome is damage.
     *
     * @return array<string, mixed>
     */
    public function config(object $connection): array
    {
        $config = json_decode((string) ($connection->config ?? ''), true);

        return is_array($config) ? $config : [];
    }

    /**
     * Persist a row's operational state, leaving any encrypted secret alone.
     *
     * @param  array<string, mixed>  $config
     */
    public function writeConfig(object $connection, array $config): void
    {
        DB::table($connection->source_table)
            ->where('id', $connection->id)
            ->update([
                'config' => json_encode($config),
                'updated_at' => now(),
            ]);
    }

    /**
     * `business_id` is selected on the store scope too, where it is not the
     * column being matched: a store row's money belongs to the business's books,
     * and a caller that had to fetch it separately would be a caller that
     * eventually forgot to.
     *
     * The provider comes from the `payment_methods` row the connection points
     * at, through a left join rather than an inner one: a connection whose method
     * row has gone is still a connection holding a business's money, and dropping
     * it from a lookup would hide exactly the account a payout then cannot find.
     *
     * @return array<int, string>
     */
    private function columns(string $scope): array
    {
        return [
            'c.id',
            'c.business_id',
            'c.gateway_account_id',
            'c.config',
            'pm.code as provider',
            'c.'.$scope.' as scope_id',
        ];
    }

    private function rowFor(string $table, string $scope, int $id, ?string $provider = null): ?object
    {
        $row = DB::table($table.' as c')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'c.payment_method_id')
            ->where('c.'.$scope, $id)
            ->whereNotNull('c.gateway_account_id')
            ->where('c.gateway_account_id', '!=', '')
            ->when($provider !== null, fn ($query) => $query->where('pm.code', $provider))
            ->first($this->columns($scope));

        if ($row !== null) {
            $row->source_table = $table;
        }

        return $row;
    }
}
