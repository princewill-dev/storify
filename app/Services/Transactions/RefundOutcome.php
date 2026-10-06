<?php

namespace App\Services\Transactions;

use App\Models\Transaction;

/**
 * WS-18 — outcome of a refund attempt.
 *
 * The money movement either succeeds (carrying the refreshed transaction) or
 * fails in one of two shapes the controller turns into a 422: the store
 * balance cannot cover it (legacy surfaced a friendly naira message), or the
 * transaction itself failed. Keeping the distinction here keeps the HTTP
 * message strings in the controller.
 */
final readonly class RefundOutcome
{
    private function __construct(
        public ?Transaction $transaction,
        public ?int $insufficientBalanceKobo,
        public ?string $failure,
    ) {}

    public static function refunded(Transaction $transaction): self
    {
        return new self($transaction, null, null);
    }

    public static function insufficientBalance(int $balanceKobo): self
    {
        return new self(null, $balanceKobo, null);
    }

    public static function failed(string $message): self
    {
        return new self(null, null, $message);
    }

    public function hasFailed(): bool
    {
        return $this->insufficientBalanceKobo !== null || $this->failure !== null;
    }
}
