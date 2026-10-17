<?php

namespace App\Services\Transactions;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Jobs\SettlePayouts;
use App\Mail\InvoicePaymentReceiptMail;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Repositories\Management\TransactionRepository;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Digital\DigitalDeliveryService;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\Data\RefundRequest;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Money\Naira;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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
        private readonly PaymentGatewayManager $manager,
        private readonly PlatformBillingGateway $platform,
        private readonly CustodyService $custody,
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
            $invoice = $transaction->invoice;

            if ($invoice) {
                // An invoice payment advances the invoice, not an order. Locked
                // on the invoice row, which is the thing two confirmations
                // would otherwise both increment.
                $invoice = Invoice::query()
                    ->whereKey($invoice->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $invoice->amount_paid = (float) $invoice->amount_paid + (float) $transaction->amount;

                if ($invoice->isFullyPaid()) {
                    $invoice->status = InvoiceStatus::PAID;
                    $invoice->paid_at = now();
                } elseif ((float) $invoice->amount_paid > 0) {
                    $invoice->status = InvoiceStatus::PARTIAL;
                }

                $invoice->save();
            } else {
                $order = $transaction->order;

                if ($order) {
                    $order->amount_paid = (float) $order->amount_paid + (float) $transaction->amount;

                    if ($order->isFullyPaid() && $order->status === OrderStatus::PENDING) {
                        $order->status = OrderStatus::ACCEPTED;
                    }

                    $order->save();
                }
            }

            // A managed invoice sale is money in the business's account at the
            // provider, not a balance we hold, so it must not be credited here.
            // An order keeps the credit it has always had: the legs reaching
            // this method for an order are not platform-managed.
            $shouldCredit = $invoice === null || ! $transaction->isPlatformManaged();

            if (! $shouldCredit && $store === null) {
                return;
            }

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
        });

        $transaction = $transaction->fresh();

        if ($transaction->invoice) {
            $this->ledger->safe(fn () => $this->ledger->postInvoice($transaction->invoice, $actorId));

            if ($transaction->isPlatformManaged()) {
                $this->ledger->safe(fn () => $this->ledger->postManagedPaymentReceived(
                    $transaction,
                    $transaction->platformFeeKobo(),
                    $actorId,
                    custody: true,
                ));

                $this->custody->recordCollectedSale($transaction, $transaction->settlementProviderCode());

                SettlePayouts::forSale($transaction);
            } else {
                $this->ledger->safe(fn () => $this->ledger->postPaymentReceived($transaction, $actorId));
            }

            $this->queueInvoiceReceipt($transaction);
        } else {
            $this->ledger->safe(fn () => $this->ledger->postPaymentReceived($transaction, $actorId));

            if ($transaction->order && $transaction->order->isFullyPaid()) {
                $this->digital->deliverSafely($transaction->order);
            }

            $this->mails->queue($transaction, 'confirmed');
        }

        Log::info('api.management.payment_confirmed', [
            'user_id' => $actorId,
            'transaction_id' => $transaction->id,
            'amount_kobo' => $this->amountInKobo($transaction),
        ]);

        return $transaction;
    }

    /**
     * The invoice receipt, once. A mail failure is logged and never allowed to
     * fail a payment the operator has already confirmed.
     */
    private function queueInvoiceReceipt(Transaction $transaction): void
    {
        $invoice = $transaction->invoice;

        if (! $invoice) {
            return;
        }

        $to = $invoice->recipient_email ?: $invoice->customer?->email;

        if (! $to || str_contains($to, '@walkin.local')) {
            return;
        }

        try {
            Mail::to($to)->queue(new InvoicePaymentReceiptMail($invoice, $transaction));
        } catch (\Throwable $e) {
            Log::error('invoice_receipt_mail_failed', ['error' => $e->getMessage()]);
        }
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
     * Return the money to the customer, unwind the order's amount paid, and run
     * the post-commit hooks. A shortfall is reported, not thrown, so the
     * controller can render the legacy friendly message.
     *
     * **A sale the platform processed on the business's behalf is refunded
     * through the provider, not out of a store balance**, and the provider call
     * happens *before* anything is written: the money has to actually go back to
     * the customer's card, and a refund that flipped the row to refunded while
     * the provider refused would say the customer had been repaid when they had
     * not. It also means this method can be retried safely — a second attempt
     * re-asks the provider, and Bachs mints its own refund reference each time
     * (see `BachsGateway::refund()`), so a retry after a genuine failure is a new
     * refund rather than a silent no-op.
     *
     * A sale charged through the business's own keys keeps exactly the behaviour
     * it has always had: the store's wallet is debited, and no provider is
     * called.
     */
    public function refund(Transaction $transaction, ?Order $order, Store $store, string $reason, int $actorId): RefundOutcome
    {
        $managed = $transaction->isPlatformManaged();

        if ($managed) {
            $outcome = $this->refundAtProvider($transaction, $reason, $actorId);

            if ($outcome !== null) {
                return $outcome;
            }
        }

        try {
            DB::transaction(function () use ($transaction, $order, $store, $reason, $actorId, $managed) {
                $amountInKobo = $this->amountInKobo($transaction);

                $updates = [
                    'status' => TransactionStatus::REFUNDED->value,
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'refund_reason' => $reason,
                        'refunded_at' => now()->toDateTimeString(),
                        'refunded_by' => $actorId,
                    ]),
                ];

                // Only when a balance of ours moved. A managed sale's money sits
                // in the business's own account at the provider, so there is
                // nothing in `stores.balance` to take back — and writing the
                // before/after pair for it would record a movement that never
                // happened.
                if (! $managed) {
                    $store->lockForUpdate();
                    $balanceBefore = (int) $store->balance;
                    $store->debitBalance($amountInKobo);

                    $updates['balance_updated_at'] = now();
                    $updates['metadata']['refund_balance_before'] = $balanceBefore;
                    $updates['metadata']['refund_balance_after'] = (int) $store->fresh()->balance;
                }

                $transaction->update($updates);

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

        // And, for a sale the platform collected, the custody half of it: the
        // business stops being owed its share, and the platform's own collection
        // entry is reversed with it.
        //
        // Reached only for a managed sale, because that is the only kind whose
        // money ever entered the wallet — but *not* reached for every managed
        // sale either, and deliberately so: a split-at-source charge has no
        // `managed:collect` entry to undo and no wallet credit to take back, and
        // the method finds that out from the row rather than from the custody
        // flag, which may have been switched between the charge and the refund.
        if ($managed) {
            $this->custody->unwindCollectedSale(
                $transaction,
                'Refund '.$transaction->reference,
                $actorId,
            );
        }

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

    /**
     * Ask the provider to put a platform-collected sale's money back on the
     * customer's card.
     *
     * Returns null when the refund was accepted — or when there is nothing this
     * method can usefully do — and a failed {@see RefundOutcome} when the
     * provider refused, which the caller returns as-is without writing anything.
     *
     * Always the **platform's** credentials, and now nothing else. The platform
     * took the charge, so the platform is who the customer's bank is asked to
     * reverse it with — and under collect-and-pay-out the gross landed in
     * Storify's provider balance as a whole, so reversing it goes back to that
     * same balance.
     *
     * The account header this used to attach is gone, and with it the one case
     * that could not be served: a charge taken before its business was flipped,
     * whose refund had to be reversed out of the business's own account. Those
     * charges have all been paid out and reconciled by the end of the migration,
     * and there is no per-business account left to address — see
     * {@see PlatformBillingGateway}.
     */
    private function refundAtProvider(Transaction $transaction, string $reason, int $actorId): ?RefundOutcome
    {
        $provider = $transaction->settlementProviderCode();

        if ($provider === '') {
            // Nothing on the row says which provider took this charge, and
            // guessing is how a refund ends up filed against the wrong merchant
            // account. Refused rather than defaulted.
            Log::error('api.management.payment_refund_no_provider', [
                'transaction_id' => $transaction->id,
            ]);

            return RefundOutcome::failed(
                'This payment was processed by Storify, but which provider took it could no longer be '
                .'determined, so it cannot be refunded automatically.'
            );
        }

        $driver = $this->manager->driver($provider);

        if ($driver === null) {
            return RefundOutcome::failed('The provider this payment was taken through is not available in this build.');
        }

        $result = $driver->refund(
            new RefundRequest(
                reference: (string) $transaction->reference,
                amountMinor: $this->amountInKobo($transaction),
                providerId: $transaction->providerChargeId(),
                reason: $reason,
            ),
            $this->platform->credentialsFor($provider),
        );

        if (! $result->success) {
            Log::error('api.management.payment_refund_refused', [
                'transaction_id' => $transaction->id,
                'provider' => $provider,
                'message' => $result->message,
            ]);

            return RefundOutcome::failed($result->message ?? 'The provider refused this refund.');
        }

        Log::info('api.management.payment_refund_at_provider', [
            'user_id' => $actorId,
            'transaction_id' => $transaction->id,
            'provider' => $provider,
            'refund_id' => $result->refundId,
            'status' => $result->status,
        ]);

        return null;
    }
}
