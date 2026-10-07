<?php

namespace App\Services\Management;

use App\Models\Store;
use App\Models\StoreBank;
use App\Repositories\Management\PaymentSettingsRepository;
use Illuminate\Support\Facades\DB;

/**
 * WS-11 — the store-side assignment workflows: assigning a bank account to a
 * store (which also turns the store's bank_transfer method on) and removing
 * one (which turns it off again only when no account remains).
 *
 * A store accepts a specific account, not just the abstract bank_transfer
 * method, so bank assignment moves the store_bank pivot and the
 * store_payment_method pivot together.
 *
 * Each workflow opens its own DB::transaction and keeps the exact ordering the
 * controller had — pivot writes first, then the derived method state. The
 * controller keeps the HTTP contract: the store guard, the 404 for a bank the
 * business does not own, the log line, the envelope and the message strings.
 */
final class StorePaymentMethodService
{
    public function __construct(private readonly PaymentSettingsRepository $repository) {}

    public function assignBank(Store $store, StoreBank $bank): void
    {
        DB::transaction(function () use ($store, $bank) {
            $this->repository->attachBankToStore($store, (int) $bank->id);
            $this->attachBankTransferMethod($store);
        });
    }

    public function removeBank(Store $store, StoreBank $bank): void
    {
        DB::transaction(function () use ($store, $bank) {
            $this->repository->detachBankFromStore($store, (int) $bank->id);

            // bank_transfer stays available only while the store accepts at
            // least one account.
            if ($this->repository->countAssignedBanks($store) === 0) {
                $method = $this->repository->findPaymentMethodByCode('bank_transfer');

                if ($method) {
                    $this->repository->deleteStoreMethod((int) $store->id, (int) $method->id);
                }
            }
        });
    }

    private function attachBankTransferMethod(Store $store): void
    {
        $method = $this->repository->findPaymentMethodByCode('bank_transfer');

        if (! $method) {
            return;
        }

        if ($this->repository->storeMethodExists((int) $store->id, (int) $method->id)) {
            $this->repository->activateStoreMethod((int) $store->id, (int) $method->id);

            return;
        }

        $this->repository->createStoreMethod((int) $store->id, (int) $method->id);
    }
}
