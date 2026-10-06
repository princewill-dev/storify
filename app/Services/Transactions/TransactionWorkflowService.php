<?php

namespace App\Services\Transactions;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Repositories\Management\TransactionRepository;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Digital\DigitalDeliveryService;
use App\Support\Money\Naira;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WS-18 — the money movement behind confirm/reject/refund.
 *
 * Transaction boundaries, balance locking, the ledger posting, digital
 * delivery and the payment mails all live here; the controller keeps the
 * status guards, messages and status codes.
 */
class TransactionWorkflowService
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly PaymentMailDispatcher $mails,
        private readonly LedgerPostingService $ledger,
        private readonly DigitalDeliveryService $digital,
    ) {}

    /**
     * Credit the store under lock, settle the order's amount paid (accepting
     * it when this payment completes it), then run the post-commit hooks.
     */
    public function confirm(Transaction $transaction, int $actorId): Transaction
    {
        DB::transaction(function () use ($transaction) {
            $transaction->update(['status' => TransactionStatus::CONFIRMED->value]);

            $store = $this->transactions->storeFor($transaction);

            if (! $store) {
                throw new \RuntimeException('Transaction has no associated store.');
            }

            $amountInKobo = $this->amountInKobo($transaction);
            $store->lockForUpdate();
            $balanceBefore = (int) $store->balance;
            $store->creditBalance($amountInKobo);

            $transaction->update([
                'balance_updated_at' => now(),
                'store_balance_before' => $balanceBefore,
                'store_balance_after' => (int) $store->fresh()->balance,
            ]);

            $order = $transaction->order;

            if ($order) {
                $order->amount_paid = (float) $order->amount_paid + (float) $transaction->amount;

                if ($order->isFullyPaid() && $order->status === OrderStatus::PENDING) {
                    $order->status = OrderStatus::ACCEPTED;
                }

                $order->save();
            }
        });

        $transaction = $transaction->fresh();

        $this->ledger->safe(fn () => $this->ledger->postPaymentReceived($transaction, $actorId));

        if ($transaction->order && $transaction->order->isFullyPaid()) {
            $this->digital->deliverSafely($transaction->order);
        }

        $this->mails->queue($transaction, 'confirmed');

        Log::info('api.management.payment_confirmed', [
            'user_id' => $actorId,
            'transaction_id' => $transaction->id,
            'amount_kobo' => $this->amountInKobo($transaction),
        ]);

        return $transaction;
    }

    /**
     * Cancel the payment, record the rejection metadata and notify. Invoice
     * payments short-circuit before the mail block — legacy crashed there on
     * the missing order, so invoice rejections notify nobody.
     */
    public function reject(Transaction $transaction, ?string $reason, int $actorId): Transaction
    {
        $transaction->update([
            'status' => TransactionStatus::CANCELED->value,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'rejection_reason' => $reason,
                'rejected_at' => now()->toDateTimeString(),
                'rejected_by' => $actorId,
            ]),
        ]);

        $transaction = $transaction->fresh();

        // Legacy short-circuited invoice payments before the mail block (which
        // also crashed on the missing order). Invoice rejections notify nobody.
        if ($transaction->invoice) {
            return $transaction;
        }

        $this->mails->queue($transaction, 'rejected', $reason);

        Log::info('api.management.payment_rejected', [
            'user_id' => $actorId,
            'transaction_id' => $transaction->id,
            'reason' => $reason,
        ]);

        return $transaction;
    }

    /**
     * Debit the store under lock and unwind the order's amount paid, then run
     * the post-commit hooks. A shortfall is reported, not thrown, so the
     * controller can render the legacy friendly message.
     */
    public function refund(Transaction $transaction, ?Order $order, Store $store, string $reason, int $actorId): RefundOutcome
    {
        try {
            DB::transaction(function () use ($transaction, $order, $store, $reason, $actorId) {
                $amountInKobo = $this->amountInKobo($transaction);

                $store->lockForUpdate();
                $balanceBefore = (int) $store->balance;
                $store->debitBalance($amountInKobo);

                $transaction->update([
                    'status' => TransactionStatus::REFUNDED->value,
                    'balance_updated_at' => now(),
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'refund_reason' => $reason,
                        'refunded_at' => now()->toDateTimeString(),
                        'refunded_by' => $actorId,
                        'refund_balance_before' => $balanceBefore,
                        'refund_balance_after' => (int) $store->fresh()->balance,
                    ]),
                ]);

                if ($order) {
                    $order->amount_paid = max(0, (float) $order->amount_paid - (float) $transaction->amount);
                    $order->save();
                }
            });
        } catch (\Throwable $e) {
            // The legacy screen surfaced a friendly naira message here; the
            // raw exception leaked through the base API.
            if (str_contains($e->getMessage(), 'Insufficient balance')) {
                return RefundOutcome::insufficientBalance((int) ($store->fresh()->balance ?? 0));
            }

            Log::error('api.management.payment_refund_failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return RefundOutcome::failed($e->getMessage());
        }

        $transaction = $transaction->fresh();

        $this->ledger->safe(fn () => $this->ledger->postRefund($transaction, $actorId));

        $this->mails->queue($transaction, 'refunded', $reason);

        Log::info('api.management.payment_refunded', [
            'user_id' => $actorId,
            'transaction_id' => $transaction->id,
            'amount_kobo' => $this->amountInKobo($transaction),
            'reason' => $reason,
        ]);

        return RefundOutcome::refunded($transaction);
    }

    private function amountInKobo(Transaction $transaction): int
    {
        return Naira::koboFromRounded($transaction->amount);
    }
}
