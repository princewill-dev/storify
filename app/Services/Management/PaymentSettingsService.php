<?php

namespace App\Services\Management;

use App\Models\PaymentMethod;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use App\Repositories\Management\PaymentSettingsRepository;
use App\Services\Payments\Payouts\PayoutBankResolver;
use App\Support\Payments\GatewayGrant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * WS-11 — the multi-table payment-configuration workflows: adding, promoting
 * and deleting business bank accounts (including the compensating cleanup
 * when the last account a store accepted is deleted), connecting and removing
 * gateways, and the payment-method → store assignment pivots.
 *
 * Each workflow opens its own DB::transaction and keeps the exact ordering
 * the controller had (pivot writes first, compensation after, business-level
 * bank_transfer sync last). The controller keeps the HTTP contract — status
 * codes, refusal messages and the 404/403/422 guards around a `{type}/{id}`
 * resolution.
 */
final class PaymentSettingsService
{
    public function __construct(
        private readonly PaymentSettingsRepository $repository,
        private readonly PayoutBankResolver $payoutBanks,
    ) {}

    /**
     * The platform's payment-method row for a code. A missing row means the
     * platform is misconfigured, not the request — hence the 500.
     */
    public function paymentMethodOrFail(string $code): PaymentMethod
    {
        $method = $this->repository->findPaymentMethodByCode($code);

        if (! $method) {
            abort(500, 'The '.$code.' payment method is not configured on this platform.');
        }

        return $method;
    }

    /**
     * Adding a bank connects bank_transfer for the business — legacy did this
     * too, but only on the business pivot. The store pivot follows when the
     * caller picked a store.
     *
     * **And the business's first account is where its money is paid.** The
     * setup step that asks for it reads "Connect your bank account. You will
     * receive your payouts here.", and until this line existed that was copy the
     * write did not honour: `is_primary` went to the bank-transfer rail while
     * `businesses.payout_bank_id` — the column `PayoutSweepService` reads and
     * nothing else does — stayed null. So a business that named an account in
     * the wizard held money that nothing would ever send, and its wallet said
     * `no_bank` while its bank account sat on the same screen.
     *
     * **Only the first, and that is the whole of the rule.** A business adding a
     * second account is managing a list, not naming a destination, and the send
     * reads this column at send time: letting a later add write it would
     * redirect a live payout to whichever account was added last. Naming a
     * destination among several is a deliberate act with its own place to do it
     * ({@see PaymentGatewayService::setPayoutBank()}), and this is deliberately
     * not it.
     *
     * Not gated on the store scope. The first account a business puts on file is
     * the account it named, whether or not the caller also assigned it to a
     * store, and a store-scoped first add is still the business's only account.
     *
     * @param  array<string, mixed>  $data
     */
    public function addBankAccount(int $businessId, array $data, ?Store $store): StoreBank
    {
        $isFirst = ! $this->repository->businessHasBanks($businessId);

        // How each payout provider sees this account, worked out **before**
        // anything is written, because a refusal has to stop the write rather than
        // arrive after the account already exists. Two things follow from the
        // ordering, and both matter: the account is never created and then
        // deleted, and the check runs outside the transaction so a provider round
        // trip is not holding one open.
        //
        // Worked out for every rail rather than only the one in use, so that a
        // processor switch needs nothing from the business — see
        // {@see \App\Services\Payments\Payouts\PayoutBankResolver}.
        // Unsaved, and it stays that way: only its bank name, code, account number
        // and account name are read. It exists so the resolution and the guard can
        // talk about "this account" before there is a row for one.
        $candidate = new StoreBank([
            'bank_name' => $data['bank_name'],
            'bank_code' => $data['bank_code'],
            'account_number' => $data['account_number'],
            'account_name' => $data['account_name'],
        ]);

        $resolutions = $this->payoutBanks->resolveAll($candidate);

        // **Only the first account is held to this.** The first is the one that
        // becomes the payout destination, so a provider that cannot pay into it is
        // a real problem; a later account is a row on a list, and `store_banks` is
        // also where customer bank transfers are collected — so refusing to record
        // an account because it cannot be paid *out* to would break collecting
        // *into* it, which is a different question entirely.
        if ($isFirst) {
            $this->payoutBanks->guardDefault($candidate, $resolutions);
        }

        $bank = DB::transaction(function () use ($businessId, $data, $store, $isFirst, $resolutions) {
            $bank = $this->repository->createBank([
                'business_id' => $businessId,
                'bank_name' => $data['bank_name'],
                'bank_code' => $data['bank_code'],
                'account_number' => $data['account_number'],
                'account_name' => $data['account_name'],
                'is_primary' => $isFirst,
                'is_verified' => true,
            ]);

            if ($isFirst) {
                $this->repository->setPayoutBankIfUnset($businessId, (int) $bank->getKey());
            }

            // Written inside the transaction with the account they are about: a
            // row describing an account that was rolled back would be a record of
            // a destination that does not exist.
            $this->payoutBanks->persist($bank, $resolutions);

            $this->syncBankTransferMethod($businessId);

            if ($store !== null) {
                $this->assignBankToStore($bank, $store);
            }

            return $bank;
        });

        return $bank;
    }

    /**
     * Partial semantics: editing the name must not silently demote the
     * account, so primary only moves when the caller asks for it.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateBankAccount(StoreBank $bank, array $data, int $businessId): void
    {
        DB::transaction(function () use ($bank, $data, $businessId) {
            $attributes = ['account_name' => $data['account_name']];

            if (($data['is_primary'] ?? false) === true) {
                $this->repository->clearPrimaryExcept($businessId, (int) $bank->getKey());

                $attributes['is_primary'] = true;
            }

            $this->repository->updateBank($bank, $attributes);

            $this->syncBankTransferMethod($businessId);
        });
    }

    /**
     * The store_bank pivot cascades with the account, but the store-level
     * bank_transfer method does not: a store that accepted only this account
     * would otherwise keep offering transfers with nowhere to send them. The
     * stores it served are captured first, and the method is stripped only
     * from those left with no account at all.
     *
     * **Removing the primary promotes the oldest surviving account**, rather
     * than leaving the business with none. The flag decides which account the
     * bank-transfer rail advertises, and the console no longer offers a way to
     * set it — that was merged into "Paid into" — so a business that removed
     * the flagged account would otherwise hold a set of accounts none of which
     * is the default, with no screen that could say otherwise. Promoted here,
     * inside the same transaction as the delete, so the flag is never observably
     * absent.
     */
    public function deleteBankAccount(StoreBank $bank, int $businessId): void
    {
        DB::transaction(function () use ($bank, $businessId) {
            $storeIds = $this->repository->storeIdsServedByBank($bank, $businessId);
            $wasPrimary = (bool) $bank->is_primary;

            $this->repository->deleteBank($bank);

            if ($wasPrimary) {
                $nextId = $this->repository->oldestBankId($businessId);

                if ($nextId !== null) {
                    $next = $this->repository->findBank($nextId);

                    if ($next !== null) {
                        $this->repository->updateBank($next, ['is_primary' => true]);
                    }
                }
            }

            $method = $this->repository->findPaymentMethodByCode('bank_transfer');

            if ($method && $storeIds->isNotEmpty()) {
                $stillBanked = $this->repository->storeIdsWithAnyBank($storeIds);

                $emptyStoreIds = $storeIds->diff($stillBanked);

                if ($emptyStoreIds->isNotEmpty()) {
                    $this->repository->detachPaymentMethodFromStores($emptyStoreIds, $method->id);
                }
            }

            $this->syncBankTransferMethod($businessId);
        });
    }

    public function setPrimaryBank(StoreBank $bank, int $businessId): void
    {
        DB::transaction(function () use ($bank, $businessId) {
            $this->repository->clearAllPrimary($businessId);
            $this->repository->updateBank($bank, ['is_primary' => true]);
            $this->syncBankTransferMethod($businessId);
        });
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function connectGateway(int $businessId, PaymentMethod $method, array $config): void
    {
        // The same refusal the WS-39 connect makes, and for the same reason: a
        // business takes payments through Storify unless an admin has granted it
        // otherwise. Leaving it to the newer surface would make the older one a
        // way around the rule, and the rule is not about which screen was used.
        //
        // Only keyed providers are gated: bank transfer is the business's own
        // bank accounts by definition.
        GatewayGrant::assertMayConnectOwnKeys($businessId, (string) $method->code);

        DB::transaction(function () use ($businessId, $method, $config) {
            $this->repository->insertBusinessGateway($businessId, (int) $method->id, $config);

            // Mirror the keys onto already-assigned stores so the storefront
            // checkout (which reads `store_payment_method.api_keys`) can use
            // them. No-op until that column exists — see mirrorGatewayKeys().
            $this->repository->mirrorGatewayKeys($businessId, $method, $config);
        });
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function updateGatewayConfig(object $row, PaymentMethod $method, array $config, int $businessId): void
    {
        DB::transaction(function () use ($row, $method, $config, $businessId) {
            $this->repository->updateBusinessGatewayConfig((int) $row->id, $config);

            $this->repository->mirrorGatewayKeys($businessId, $method, $config);
        });
    }

    /**
     * Removing a gateway is scoped to this business's stores. Legacy deleted
     * store_payment_method by payment_method_id across *every* store
     * platform-wide, silently unassigning other businesses.
     *
     * @param  Collection<int, int>|array<int, int>  $storeIds
     */
    public function removeGateway(object $row, Collection|array $storeIds): void
    {
        DB::transaction(function () use ($row, $storeIds) {
            $this->repository->deleteBusinessGateway((int) $row->id);

            $this->repository->detachPaymentMethodFromStores($storeIds, (int) $row->payment_method_id);
        });
    }

    public function assignMethod(Store $store, PaymentMethod $method, string $type, int $id, int $businessId): void
    {
        DB::transaction(function () use ($store, $method, $type, $id, $businessId) {
            $this->attachMethodToStore($store, (int) $method->id, $businessId);

            // A bank assignment is per account, not just per method, so the
            // store's accepted bank list moves too.
            if ($type === 'bank') {
                $this->repository->attachBankToStore($store, $id);
            }
        });
    }

    public function unassignMethod(Store $store, PaymentMethod $method, string $type, int $id): void
    {
        DB::transaction(function () use ($store, $method, $type, $id) {
            // A bank assignment is per account, not just per method: removing
            // one of several accounts must leave bank_transfer in place. Only
            // the last account turns the store's transfers off.
            if ($type === 'bank') {
                $this->repository->detachBankFromStore($store, $id);

                if ($this->repository->countAssignedBanks($store) > 0) {
                    return;
                }
            }

            $this->repository->deleteStoreMethod((int) $store->id, (int) $method->id);
        });
    }

    /**
     * Resolve a `{type}/{id}` pair into a payment method plus the store lists
     * around it. `gateway` ids are business gateway pivot ids, `bank` ids are
     * store_banks ids — one unambiguous resolution. Unknown types are a
     * validation failure (the legacy ambiguous `type` string is deliberately
     * not cloned).
     */
    public function resolveMethod(User $user, string $type, string $id): ResolvedPaymentMethod
    {
        $businessId = (int) $user->business_id;

        if ($type === 'gateway') {
            $row = $this->repository->gatewayRow((int) $id);

            if (! $row) {
                abort(404, 'Payment method not found.');
            }

            if ((int) $row->business_id !== $businessId) {
                abort(403, 'You do not have access to this gateway.');
            }

            $method = $this->repository->findPaymentMethodOrFail((int) $row->payment_method_id);

            return new ResolvedPaymentMethod(
                $method,
                'gateway',
                $row,
                $this->repository->storesAssignedToGateway($user, (int) $method->id),
                $this->repository->storesNotAssignedToGateway($user, (int) $method->id),
            );
        }

        if ($type === 'bank') {
            $bank = $this->repository->findBank((int) $id);

            if (! $bank) {
                abort(404, 'Payment method not found.');
            }

            if ((int) $bank->business_id !== $businessId) {
                abort(403, 'You do not have access to this bank account.');
            }

            $method = $this->paymentMethodOrFail('bank_transfer');

            return new ResolvedPaymentMethod(
                $method,
                'bank',
                $bank,
                $this->repository->storesAssignedToBank($user, (int) $bank->id),
                $this->repository->storesNotAssignedToBank($user, (int) $bank->id),
            );
        }

        throw ValidationException::withMessages(['type' => 'Unknown payment method type.']);
    }

    /**
     * Keep the business-level bank_transfer method in step with its banks:
     * config carries the primary account (legacy shape) and the method turns
     * off when the last bank is removed, so checkout cannot offer a transfer
     * with nowhere to send it.
     */
    private function syncBankTransferMethod(int $businessId): void
    {
        $method = $this->repository->findPaymentMethodByCode('bank_transfer');

        if (! $method) {
            return;
        }

        $banks = $this->repository->primaryFirstBanks($businessId);

        if ($banks->isEmpty()) {
            $this->repository->upsertBusinessMethod($businessId, $method, [], false);

            return;
        }

        $primary = $banks->firstWhere('is_primary', true) ?? $banks->first();

        $this->repository->upsertBusinessMethod($businessId, $method, [
            'bank_name' => $primary->bank_name,
            'account_number' => $primary->account_number,
            'account_name' => $primary->account_name,
            'bank_accounts_count' => $banks->count(),
        ], true);
    }

    private function attachMethodToStore(Store $store, int $methodId, int $businessId): void
    {
        if ($this->repository->storeMethodExists((int) $store->id, $methodId)) {
            $this->repository->activateStoreMethod((int) $store->id, $methodId);

            return;
        }

        $this->repository->createStoreMethod((int) $store->id, $methodId);

        $method = $this->repository->findPaymentMethod($methodId);

        if ($method) {
            $businessMethod = $this->repository->businessMethodRow($businessId, $method->code);

            if ($businessMethod) {
                $config = json_decode($businessMethod->config ?: '{}', true) ?: [];
                $this->repository->mirrorGatewayKeys($businessId, $method, $config);
            }
        }
    }

    private function assignBankToStore(StoreBank $bank, Store $store): void
    {
        $this->repository->attachBankToStore($store, (int) $bank->id);

        $method = $this->repository->findPaymentMethodByCode('bank_transfer');

        if ($method) {
            $this->attachMethodToStore($store, (int) $method->id, (int) $bank->business_id);
        }
    }
}
