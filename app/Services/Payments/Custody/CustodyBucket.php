<?php

namespace App\Services\Payments\Custody;

use App\Models\Business;
use App\Models\Store;
use App\Models\Transaction;

/**
 * Which wallet a sale's money lives in, and every write that moves it.
 *
 * ## One answer, asked once
 *
 * A sale's store is *derivable* — through its order, or failing that its
 * invoice — and `PayoutAllocationService::storeIdFor()` does exactly that. What
 * it cannot do reliably is derive the same answer twice. An order reassigned
 * between the credit and the settlement that promotes it, or a store deleted in
 * between, would promote out of the business bucket while the money sat on a
 * store, and the store's pending would be stranded for ever with nothing to
 * explain why.
 *
 * So the bucket is decided **once**, when the sale is credited, and recorded on
 * `transactions.custody_store_id`. Everything after that reads it back rather
 * than asking again. The same reasoning as the destination snapshot on a payout:
 * a record of the decision, not a re-derivation of it.
 *
 * ## Why the operations live here rather than at the call sites
 *
 * `CustodyService` had five places that named a wallet: credit on collection,
 * promote on settlement, two refund paths, and the payout reservation. Each
 * would have had to grow the same "is it the store or the business" branch, and
 * five copies of that branch is five chances to get one of them wrong — the
 * failure mode being money credited to one bucket and settled from another.
 * They call through here instead, so the branch exists once.
 *
 * ## Off by default
 *
 * `custody.per_store_wallets` is read only here. With it off, every method
 * resolves the business and the platform behaves exactly as it did before stores
 * had wallets — which is what lets this ship dark and be flipped in one place.
 */
final class CustodyBucket
{
    public function enabled(): bool
    {
        return (bool) config('custody.per_store_wallets', false);
    }

    /**
     * The wallet this sale's money belongs in.
     *
     * Reads `custody_store_id` when it is set — the recorded decision. When it is
     * not, and only for a sale being credited now, it derives the store the same
     * way the allocation service does. A sale with neither an order nor an
     * invoice is a managed till leg and belongs to the business, which is the
     * bucket that exists for it.
     */
    public function scopeFor(Transaction $transaction, Business $business): Business|Store
    {
        if (! $this->enabled()) {
            return $business;
        }

        $storeId = $transaction->custody_store_id
            ?? app(PayoutAllocationService::class)->storeIdFor($transaction);

        if ($storeId === null) {
            return $business;
        }

        // A store that no longer exists, or belongs to another business, is not a
        // bucket this sale may be credited to. Falling back to the business is
        // the answer that needs no repair: the money is still somewhere Storify
        // will pay out, rather than on a row that has been deleted.
        $store = Store::query()
            ->whereKey($storeId)
            ->where('business_id', $business->id)
            ->first();

        return $store ?? $business;
    }

    /**
     * The store a sale is being credited to, for the record on the transaction.
     *
     * Null means the business bucket, which is also what a flag-off install
     * writes — so the column is honest either way and nothing downstream has to
     * know which mode wrote it.
     */
    public function storeIdFor(Transaction $transaction, Business $business): ?int
    {
        $scope = $this->scopeFor($transaction, $business);

        return $scope instanceof Store ? (int) $scope->getKey() : null;
    }

    public function creditPending(Transaction $transaction, Business $business, int $amountKobo): void
    {
        $this->scopeFor($transaction, $business)->creditPending($amountKobo);
    }

    public function creditAvailable(Transaction $transaction, Business $business, int $amountKobo): void
    {
        $this->scopeFor($transaction, $business)->creditAvailable($amountKobo);
    }

    public function promoteToAvailable(Transaction $transaction, Business $business, int $amountKobo): void
    {
        $this->scopeFor($transaction, $business)->promoteToAvailable($amountKobo);
    }

    public function debitPending(Transaction $transaction, Business $business, int $amountKobo): void
    {
        $this->scopeFor($transaction, $business)->debitPending($amountKobo);
    }

    public function debitAvailable(
        Transaction $transaction,
        Business $business,
        int $amountKobo,
        bool $allowNegative = false,
    ): void {
        $this->scopeFor($transaction, $business)->debitAvailable($amountKobo, $allowNegative);
    }
}
