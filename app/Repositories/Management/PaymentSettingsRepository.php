<?php

namespace App\Repositories\Management;

use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WS-11 — query building and single-statement persistence for payment
 * settings: business bank accounts, Paystack gateway pivots and the
 * payment-method → store assignment pivots.
 *
 * Reads and writes only: transaction boundaries, abort() refusals and the
 * multi-step workflows around them live in PaymentSettingsService, and the
 * HTTP contract in PaymentSettingsController.
 */
class PaymentSettingsRepository
{
    /**
     * Stores the user may reach, excluding deleted ones — the scope every
     * store-selection check shares.
     */
    public function accessibleStores(User $user): Builder
    {
        return $user->accessibleStores()->where('status', '!=', Store::STATUS_DELETED);
    }

    /**
     * @return Collection<int, Store>
     */
    public function storeOptions(User $user): Collection
    {
        return $this->accessibleStores($user)->orderBy('name')->get();
    }

    /**
     * The payment-settings list order: primary first, then by bank name.
     *
     * @return Collection<int, StoreBank>
     */
    public function businessBanks(int $businessId): Collection
    {
        return StoreBank::query()
            ->where('business_id', $businessId)
            ->orderByDesc('is_primary')
            ->orderBy('bank_name')
            ->get();
    }

    /**
     * The bank set the business-level bank_transfer config is derived from.
     * Deliberately ordered by the primary flag only (no name tiebreak): when
     * no bank is flagged primary the "first" bank is whichever the database
     * returns first, exactly as the config has always been built.
     *
     * @return Collection<int, StoreBank>
     */
    public function primaryFirstBanks(int $businessId): Collection
    {
        return StoreBank::where('business_id', $businessId)->orderByDesc('is_primary')->get();
    }

    public function businessHasBanks(int $businessId): bool
    {
        return StoreBank::where('business_id', $businessId)->exists();
    }

    public function findBank(int $id): ?StoreBank
    {
        return StoreBank::find($id);
    }

    public function bankAccountExists(int $businessId, string $accountNumber, string $bankCode): bool
    {
        return StoreBank::where('business_id', $businessId)
            ->where('account_number', $accountNumber)
            ->where('bank_code', $bankCode)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createBank(array $attributes): StoreBank
    {
        return StoreBank::create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateBank(StoreBank $bank, array $attributes): void
    {
        $bank->update($attributes);
    }

    public function clearPrimaryExcept(int $businessId, int $exceptBankId): void
    {
        StoreBank::where('business_id', $businessId)
            ->whereKeyNot($exceptBankId)
            ->update(['is_primary' => false]);
    }

    public function clearAllPrimary(int $businessId): void
    {
        StoreBank::where('business_id', $businessId)->update(['is_primary' => false]);
    }

    public function deleteBank(StoreBank $bank): void
    {
        $bank->delete();
    }

    /**
     * The stores (of this business) that had the bank assigned.
     *
     * @return Collection<int, int>
     */
    public function storeIdsServedByBank(StoreBank $bank, int $businessId): Collection
    {
        return DB::table('store_bank')
            ->where('store_bank_id', $bank->id)
            ->whereIn('store_id', Store::where('business_id', $businessId)->pluck('id'))
            ->pluck('store_id');
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $storeIds
     * @return Collection<int, int>
     */
    public function storeIdsWithAnyBank(Collection|array $storeIds): Collection
    {
        return DB::table('store_bank')->whereIn('store_id', $storeIds)->pluck('store_id');
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $storeIds
     */
    public function detachPaymentMethodFromStores(Collection|array $storeIds, int $methodId): void
    {
        DB::table('store_payment_method')
            ->whereIn('store_id', $storeIds)
            ->where('payment_method_id', $methodId)
            ->delete();
    }

    public function findPaymentMethodByCode(string $code): ?PaymentMethod
    {
        return PaymentMethod::where('code', $code)->first();
    }

    public function findPaymentMethod(int $id): ?PaymentMethod
    {
        return PaymentMethod::find($id);
    }

    public function findPaymentMethodOrFail(int $id): PaymentMethod
    {
        return PaymentMethod::findOrFail($id);
    }

    /**
     * The business gateway pivot row for a method code, joined with the
     * method's display name and code — legacy's `businessMethodRow()`.
     */
    public function businessMethodRow(int $businessId, string $code): ?object
    {
        return DB::table('business_payment_method')
            ->join('payment_methods', 'payment_methods.id', '=', 'business_payment_method.payment_method_id')
            ->where('business_payment_method.business_id', $businessId)
            ->where('payment_methods.code', $code)
            ->select('business_payment_method.*', 'payment_methods.name', 'payment_methods.code')
            ->first();
    }

    /**
     * The raw pivot row for a gateway id — the tenant guard reads its
     * business_id before anything else touches it.
     */
    public function gatewayRow(int $gatewayId): ?object
    {
        return DB::table('business_payment_method')->where('id', $gatewayId)->first();
    }

    /**
     * @return Collection<int, object>
     */
    public function businessGateways(int $businessId): Collection
    {
        return DB::table('business_payment_method')
            ->join('payment_methods', 'payment_methods.id', '=', 'business_payment_method.payment_method_id')
            ->where('business_payment_method.business_id', $businessId)
            ->where('payment_methods.type', 'gateway')
            ->select(
                'business_payment_method.id',
                'business_payment_method.payment_method_id',
                'business_payment_method.is_active',
                'business_payment_method.config',
                'business_payment_method.created_at',
                'payment_methods.name',
                'payment_methods.code',
            )
            ->orderBy('payment_methods.name')
            ->get();
    }

    /**
     * Active platform gateways the business has not connected yet.
     *
     * @return Collection<int, PaymentMethod>
     */
    public function availableGatewayMethods(int $businessId): Collection
    {
        return PaymentMethod::query()
            ->where('is_active', true)
            ->where('type', 'gateway')
            ->whereDoesntHave('businesses', fn ($q) => $q->where('businesses.id', $businessId))
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function insertBusinessGateway(int $businessId, int $methodId, array $config): void
    {
        DB::table('business_payment_method')->insert([
            'business_id' => $businessId,
            'payment_method_id' => $methodId,
            'is_active' => true,
            'config' => json_encode($config),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function updateBusinessGatewayConfig(int $rowId, array $config): void
    {
        DB::table('business_payment_method')->where('id', $rowId)->update([
            'config' => json_encode($config),
            'updated_at' => now(),
        ]);
    }

    public function setBusinessGatewayActive(int $rowId, bool $isActive): void
    {
        DB::table('business_payment_method')->where('id', $rowId)->update([
            'is_active' => $isActive,
            'updated_at' => now(),
        ]);
    }

    public function deleteBusinessGateway(int $rowId): void
    {
        DB::table('business_payment_method')->where('id', $rowId)->delete();
    }

    public function storeMethodExists(int $storeId, int $methodId): bool
    {
        return DB::table('store_payment_method')
            ->where('store_id', $storeId)
            ->where('payment_method_id', $methodId)
            ->exists();
    }

    public function activateStoreMethod(int $storeId, int $methodId): void
    {
        DB::table('store_payment_method')
            ->where('store_id', $storeId)
            ->where('payment_method_id', $methodId)
            ->update(['is_active' => true, 'updated_at' => now()]);
    }

    public function createStoreMethod(int $storeId, int $methodId): void
    {
        DB::table('store_payment_method')->insert([
            'store_id' => $storeId,
            'payment_method_id' => $methodId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function deleteStoreMethod(int $storeId, int $methodId): void
    {
        DB::table('store_payment_method')
            ->where('store_id', $storeId)
            ->where('payment_method_id', $methodId)
            ->delete();
    }

    public function attachBankToStore(Store $store, int $bankId): void
    {
        $store->assignedBanks()->syncWithoutDetaching([$bankId => ['is_active' => true]]);
    }

    public function detachBankFromStore(Store $store, int $bankId): void
    {
        $store->assignedBanks()->detach($bankId);
    }

    public function countAssignedBanks(Store $store): int
    {
        return $store->assignedBanks()->count();
    }

    /**
     * Stores that already accept the method.
     */
    public function storesAssignedToGateway(User $user, int $methodId): Builder
    {
        return $this->accessibleStores($user)
            ->whereHas('paymentMethods', fn ($q) => $q->where('payment_methods.id', $methodId)
                ->where('store_payment_method.is_active', true));
    }

    public function storesNotAssignedToGateway(User $user, int $methodId): Builder
    {
        return $this->accessibleStores($user)
            ->whereDoesntHave('paymentMethods', fn ($q) => $q->where('payment_methods.id', $methodId)
                ->where('store_payment_method.is_active', true));
    }

    public function storesAssignedToBank(User $user, int $bankId): Builder
    {
        return $this->accessibleStores($user)
            ->whereHas('assignedBanks', fn ($q) => $q->where('store_banks.id', $bankId)
                ->where('store_bank.is_active', true));
    }

    public function storesNotAssignedToBank(User $user, int $bankId): Builder
    {
        return $this->accessibleStores($user)
            ->whereDoesntHave('assignedBanks', fn ($q) => $q->where('store_banks.id', $bankId)
                ->where('store_bank.is_active', true));
    }

    /**
     * Execute one of the assigned/available store queries in its display
     * order.
     *
     * @return Collection<int, Store>
     */
    public function namedStores(Builder $query): Collection
    {
        return $query->orderBy('name')->get();
    }

    /**
     * Whether the store currently accepts the method, actively.
     */
    public function storeHasActiveMethod(Store $store, string $methodCode): bool
    {
        return DB::table('store_payment_method')
            ->join('payment_methods', 'payment_methods.id', '=', 'store_payment_method.payment_method_id')
            ->where('store_payment_method.store_id', $store->id)
            ->where('payment_methods.code', $methodCode)
            ->where('store_payment_method.is_active', true)
            ->exists();
    }

    public function updateStorePaymentMode(Store $store, string $mode): void
    {
        $store->update(['payment_mode' => $mode]);
    }

    /**
     * The business-level method row, created or updated with merged config.
     *
     * @param  array<string, mixed>  $config
     */
    public function upsertBusinessMethod(int $businessId, PaymentMethod $method, array $config, bool $isActive): void
    {
        $existing = DB::table('business_payment_method')
            ->where('business_id', $businessId)
            ->where('payment_method_id', $method->id)
            ->first();

        if ($existing) {
            DB::table('business_payment_method')->where('id', $existing->id)->update([
                'is_active' => $isActive,
                'config' => json_encode(array_merge(json_decode($existing->config ?: '{}', true) ?: [], $config)),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('business_payment_method')->insert([
            'business_id' => $businessId,
            'payment_method_id' => $method->id,
            'is_active' => $isActive,
            'config' => json_encode($config),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Gateway keys live canonically in `business_payment_method.config`
     * (legacy shape, `{public_key, secret_key}`). The storefront checkout
     * reads `store_payment_method.api_keys`, a column the current schema never
     * gained — so keys written by legacy could never reach checkout. When the
     * column exists we mirror the keys onto each assigned store; until then
     * this is a deliberate no-op and the config remains the single source.
     *
     * @param  array<string, mixed>  $config
     */
    public function mirrorGatewayKeys(int $businessId, PaymentMethod $method, array $config): void
    {
        if (empty($config['secret_key']) || ! Schema::hasColumn('store_payment_method', 'api_keys')) {
            return;
        }

        $storeIds = Store::where('business_id', $businessId)->pluck('id');

        DB::table('store_payment_method')
            ->where('payment_method_id', $method->id)
            ->whereIn('store_id', $storeIds)
            ->update([
                'api_keys' => json_encode(['public_key' => $config['public_key'] ?? null, 'secret_key' => $config['secret_key']]),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $storeIds
     */
    public function assignedBankStoreCount(StoreBank $bank, Collection|array $storeIds): int
    {
        return DB::table('store_bank')
            ->where('store_bank_id', $bank->id)
            ->where('is_active', true)
            ->whereIn('store_id', $storeIds)
            ->count();
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $storeIds
     */
    public function assignedGatewayStoreCount(object $row, Collection|array $storeIds): int
    {
        return DB::table('store_payment_method')
            ->where('payment_method_id', $row->payment_method_id)
            ->where('is_active', true)
            ->whereIn('store_id', $storeIds)
            ->count();
    }
}
