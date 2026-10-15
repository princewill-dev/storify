<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * The custody wallet: two signed buckets, and the five ways they move.
 *
 * The columns are `pending_balance`, `available_balance` and
 * `balance_updated_at` on whichever model uses this. A **business** holds the
 * money no store accounts for — `PayoutAllocationService::storeIdFor()` returns
 * null for a managed POS till leg, which has neither order nor invoice to derive
 * a store from. A **store** holds its own takings. The sum is the business's
 * real balance, and which of the two a sale lands in is decided once at credit
 * and recorded on the transaction.
 *
 * ## Why a trait and not two copies
 *
 * The logic is not merely similar between the two models, it is identical, on
 * columns with the same names and the same meanings. Two copies would be two
 * places to fix the next concurrency bug in — and the bodies below are the
 * product of several: the row is locked and re-read rather than trusting `$this`,
 * `promoteToAvailable` fails loudly on an over-promote because that means the job
 * ran twice, and `debitAvailable` refuses to overdraw for a payout while allowing
 * it for a refund. None of that is obvious enough to survive being written twice.
 *
 * ## Not to be confused with `Store::creditBalance()`
 *
 * `Store` also has an unsigned `balance` column and `creditBalance()` /
 * `debitBalance()` on it. That is a **different money**: sales charged on the
 * business's own provider keys, already sitting in the business's own account.
 * It refuses to go below zero, which is right for it and wrong here. This trait
 * does not touch it, and the two must not be summed.
 */
trait HasCustodyWallet
{
    /** Everything Storify is holding for this bucket. */
    public function walletBalance(): int
    {
        return (int) $this->pending_balance + (int) $this->available_balance;
    }

    /**
     * What can actually be paid out right now.
     *
     * Separate from {@see walletBalance()} on purpose: the difference is money
     * the provider has not settled to Storify yet, and paying it out would mean
     * Storify advancing funds it does not have.
     */
    public function payoutableBalance(): int
    {
        return (int) $this->available_balance;
    }

    /**
     * A sale collected, before the provider has settled it to Storify.
     *
     * @throws \Exception when the amount is not positive.
     */
    public function creditPending(int $amountInKobo): bool
    {
        return $this->mutateWallet('pending_balance', $amountInKobo, false);
    }

    /**
     * Put money straight into the payable bucket.
     *
     * For a payout that was refused: the money was already settled, so returning
     * it to `pending` would make the business wait a second settlement window for
     * a transfer the provider declined. It also has to be able to repair a
     * negative balance left by a refund taken after a payout, which is why it does
     * not refuse to run while available is below zero.
     */
    public function creditAvailable(int $amountInKobo): bool
    {
        return $this->mutateWallet('available_balance', $amountInKobo, true);
    }

    /**
     * Settlement cleared, so the money moved from owed-but-unsettled to payable.
     *
     * Debits pending and credits available in one locked transaction, because a
     * promote that did half the job would either create money or destroy it.
     *
     * @throws \Exception when there is not that much pending — a sign the promote
     *                    job has run twice, which is a bug worth failing loudly on
     *                    rather than quietly absorbing.
     */
    public function promoteToAvailable(int $amountInKobo): bool
    {
        if ($amountInKobo <= 0) {
            throw new \Exception('Promote amount must be positive');
        }

        return DB::transaction(function () use ($amountInKobo) {
            $row = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if ((int) $row->pending_balance < $amountInKobo) {
                throw new \Exception('Cannot promote more than is pending');
            }

            $row->decrement('pending_balance', $amountInKobo);
            $row->increment('available_balance', $amountInKobo);
            $row->forceFill(['balance_updated_at' => now()])->save();

            $this->refresh();

            return true;
        });
    }

    /**
     * Move money out: a payout reservation, or a refund of settled funds.
     *
     * `$allowNegative` is the difference between the two. A payout must never
     * overdraw — if there is not enough settled money to pay, the right answer is
     * to pay less, not to invent it. A refund must be allowed to: the business
     * already has the cash, Storify has refunded the customer from its own
     * balance, and refusing would leave the debt unrecorded rather than recovered
     * from the next sale.
     *
     * @throws \Exception when it would overdraw and is not allowed to.
     */
    public function debitAvailable(int $amountInKobo, bool $allowNegative = false): bool
    {
        if ($amountInKobo <= 0) {
            throw new \Exception('Debit amount must be positive');
        }

        return DB::transaction(function () use ($amountInKobo, $allowNegative) {
            $row = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if (! $allowNegative && (int) $row->available_balance < $amountInKobo) {
                throw new \Exception('Insufficient available balance');
            }

            $row->decrement('available_balance', $amountInKobo);
            $row->forceFill(['balance_updated_at' => now()])->save();

            $this->refresh();

            return true;
        });
    }

    /**
     * A refund against a sale the provider has not settled to us yet.
     *
     * Debits pending, never below zero: this reverses a specific credit, and that
     * credit is still sitting in pending until it is promoted. If it has already
     * been promoted, the refund belongs in {@see debitAvailable()} instead — the
     * caller knows which, because it knows whether the sale was promoted.
     *
     * @throws \Exception when it would overdraw pending.
     */
    public function debitPending(int $amountInKobo): bool
    {
        if ($amountInKobo <= 0) {
            throw new \Exception('Debit amount must be positive');
        }

        return DB::transaction(function () use ($amountInKobo) {
            $row = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if ((int) $row->pending_balance < $amountInKobo) {
                throw new \Exception('Insufficient pending balance');
            }

            $row->decrement('pending_balance', $amountInKobo);
            $row->forceFill(['balance_updated_at' => now()])->save();

            $this->refresh();

            return true;
        });
    }

    /**
     * The shared body of a single-bucket credit.
     *
     * Locks the row and re-reads it rather than trusting `$this`, so two workers
     * crediting the same row cannot both read the same starting figure.
     *
     * @throws \Exception when the amount is not positive, or when the bucket is
     *                    one a credit must never overdraw.
     */
    private function mutateWallet(string $column, int $amountInKobo, bool $allowNegative): bool
    {
        if (! in_array($column, ['pending_balance', 'available_balance'], true)) {
            throw new \Exception('Unknown wallet bucket');
        }

        if ($amountInKobo <= 0) {
            throw new \Exception('Credit amount must be positive');
        }

        return DB::transaction(function () use ($column, $amountInKobo, $allowNegative) {
            $row = static::query()->lockForUpdate()->findOrFail($this->getKey());

            if (! $allowNegative && (int) $row->{$column} < -$amountInKobo) {
                throw new \Exception('Wallet adjustment would overdraw');
            }

            $row->increment($column, $amountInKobo);
            $row->forceFill(['balance_updated_at' => now()])->save();

            $this->refresh();

            return true;
        });
    }
}
