<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TransactionController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $transactions = Transaction::query()
            ->where('business_id', $this->user($request)->business_id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), fn ($q) => $q->where('reference', 'like', '%'.$request->string('q').'%'))
            ->with(['order:id,order_number,store_id,customer_id', 'invoice:id,invoice_number,store_id', 'paymentMethod'])
            ->latest()
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            $transactions->getCollection()->map(fn (Transaction $transaction) => $this->payload($transaction))->values()->all(),
            null,
            200,
            $this->paginationMeta($transactions)
        );
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        $transaction->load(['order.items', 'order.customer', 'invoice.items', 'paymentMethod', 'storeBank']);

        return $this->ok(['transaction' => $this->payload($transaction, detailed: true)]);
    }

    public function confirm(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        if ($transaction->status !== TransactionStatus::PENDING) {
            return $this->error('Only pending transactions can be confirmed.', 409);
        }

        DB::transaction(function () use ($transaction) {
            $transaction->update(['status' => TransactionStatus::CONFIRMED->value]);

            $store = $transaction->order?->store ?? $transaction->invoice?->store;

            if (! $store) {
                throw new \RuntimeException('Transaction has no associated store.');
            }

            $amountInKobo = (int) round((float) $transaction->amount * 100);
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

        $ledger = app(\App\Services\Accounting\LedgerPostingService::class);
        $ledger->safe(fn () => $ledger->postPaymentReceived($transaction, $this->user($request)->id));

        if ($transaction->order && $transaction->order->isFullyPaid()) {
            $digital = app(\App\Services\Digital\DigitalDeliveryService::class);
            $digital->deliverSafely($transaction->order);
        }

        return $this->ok(['transaction' => $this->payload($transaction->fresh())], 'Payment confirmed.');
    }

    public function reject(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        if ($transaction->status !== TransactionStatus::PENDING) {
            return $this->error('Only pending transactions can be rejected.', 409);
        }

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $transaction->update([
            'status' => TransactionStatus::CANCELED->value,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'rejection_reason' => $data['reason'],
                'rejected_at' => now()->toDateTimeString(),
                'rejected_by' => $this->user($request)->id,
            ]),
        ]);

        return $this->ok(['transaction' => $this->payload($transaction->fresh())], 'Payment rejected.');
    }

    public function refund(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        if ($transaction->status !== TransactionStatus::CONFIRMED) {
            return $this->error('Only confirmed transactions can be refunded.', 409);
        }

        if ($transaction->invoice) {
            return $this->error('Refunds are not supported for invoice payments.', 422);
        }

        $order = $transaction->order;

        if ($order && in_array($order->status, [OrderStatus::DELIVERED, OrderStatus::COMPLETED], true)) {
            return $this->error('Cannot refund delivered/completed orders. Mark the order as returned first.', 409);
        }

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $actorId = $this->user($request)->id;

        try {
            DB::transaction(function () use ($transaction, $order, $data, $actorId) {
                $store = $transaction->order->store;
                $amountInKobo = (int) round((float) $transaction->amount * 100);

                $store->lockForUpdate();
                $balanceBefore = (int) $store->balance;
                $store->debitBalance($amountInKobo);

                $transaction->update([
                    'status' => TransactionStatus::REFUNDED->value,
                    'balance_updated_at' => now(),
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'refund_reason' => $data['reason'],
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
            return $this->error($e->getMessage(), 409);
        }

        $ledger = app(\App\Services\Accounting\LedgerPostingService::class);
        $ledger->safe(fn () => $ledger->postRefund($transaction, $this->user($request)->id));

        return $this->ok(['transaction' => $this->payload($transaction->fresh())], 'Refund processed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Transaction $transaction, bool $detailed = false): array
    {
        $data = [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
            'amount' => (float) $transaction->amount,
            'fee' => $transaction->fee_kobo !== null ? $transaction->fee_kobo / 100 : null,
            'net' => $transaction->net_kobo !== null ? $transaction->net_kobo / 100 : null,
            'currency' => $transaction->currency,
            'status' => $transaction->status instanceof TransactionStatus ? $transaction->status->value : $transaction->status,
            'order' => $transaction->order?->order_number,
            'invoice' => $transaction->invoice?->invoice_number,
            'payment_method' => $transaction->paymentMethod?->name,
            'paid_at' => $transaction->paid_at?->toISOString(),
            'created_at' => $transaction->created_at?->toISOString(),
        ];

        if ($detailed) {
            $data['gateway_reference'] = $transaction->gateway_reference;
            $data['store_balance_before'] = $transaction->store_balance_before;
            $data['store_balance_after'] = $transaction->store_balance_after;
            $data['bank'] = $transaction->storeBank ? [
                'bank_name' => $transaction->storeBank->bank_name,
                'account_number' => $transaction->storeBank->account_number,
                'account_name' => $transaction->storeBank->account_name,
            ] : null;
            $data['metadata'] = $transaction->metadata;
        }

        return $data;
    }

    private function authorizeTransaction(Request $request, Transaction $transaction): void
    {
        if ((int) $transaction->business_id !== (int) $this->user($request)->business_id) {
            abort(403, 'You do not have access to this transaction.');
        }
    }
}
