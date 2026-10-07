<?php

namespace App\Services\Management\Transaction;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Digital\DigitalDeliveryService;
use App\Support\Money\Naira;
use Illuminate\Support\Facades\DB;

/**
 * The base TransactionController's money workflows.
 *
 * Confirm and refund keep the exact sequence the controller used: the
 * DB::transaction boundary opens first, then the store row is locked, its
 * balance read and mutated, the transaction's balance columns stamped, and the
 * order's amount paid settled inside the same transaction; the ledger posting
 * and (on confirm) digital delivery run after the commit. The transaction
 * belongs to this layer, never to the repository. The controller keeps the
 * HTTP shape: status codes, message strings and the envelope.
 *
 * Deliberately separate from App\Services\Transactions\TransactionWorkflowService
 * (the WS-18 slice behind the shared routes, served by TransactionParityController):
 * that one also queues the payment mails and logs each workflow, maps an
 * insufficient-balance refund to a friendly 422 and refreshes before posting
 * the ledger; this slice keeps the base contract — no payment mail, no log,
 * the raw refund failure message. The two are not interchangeable.
 *
 * Reject is a single-row status + metadata write with no transaction, ledger
 * or notification, so it stays in the controller like the other base slices'
 * single-row writes.
 */
final class TransactionService
{
    public function __construct(
        private readonly LedgerPostingService $ledger,
        private readonly DigitalDeliveryService $digital,
    ) {}

    /**
     * Credit the store under lock, settle the order's amount paid (accepting
     * it when this payment completes it), then run the post-commit hooks.
     * Returns the same instance the inline workflow left in memory.
     */
    public function confirm(Transaction $transaction, int $actorId): Transaction
    {
        DB::transaction(function () use ($transaction) {
            $transaction->update(['status' => TransactionStatus::CONFIRMED->value]);

            $store = $transaction->order?->store ?? $transaction->invoice?->store;

            if (! $store) {
                throw new \RuntimeException('Transaction has no associated store.');
            }

            // The blunt float round the inline code used — Naira::koboFromRounded
            // is that expression verbatim.
            $amountInKobo = Naira::koboFromRounded($transaction->amount);
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

        $this->ledger->safe(fn () => $this->ledger->postPaymentReceived($transaction, $actorId));

        if ($transaction->order && $transaction->order->isFullyPaid()) {
            $this->digital->deliverSafely($transaction->order);
        }

        return $transaction;
    }

    /**
     * Debit the store under lock and unwind the order's amount paid, then post
     * the refund to the ledger. Failures are thrown for the controller to turn
     * into its raw-message 409 — this slice never rewrote them into a friendly
     * 422.
     *
     * The store is resolved through the order exactly as the inline code did:
     * invoice payments never reach here (the controller refuses them) and
     * there is no invoice fallback.
     */
    public function refund(Transaction $transaction, ?Order $order, string $reason, int $actorId): void
    {
        DB::transaction(function () use ($transaction, $order, $reason, $actorId) {
            $store = $transaction->order->store;
            $amountInKobo = Naira::koboFromRounded($transaction->amount);

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

        $this->ledger->safe(fn () => $this->ledger->postRefund($transaction, $actorId));
    }
}
