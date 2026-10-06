<?php

namespace App\Services\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Mail\OrderStatusUpdatedMail;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Management\OrderFulfilmentRepository;
use App\Services\StockLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * WS-12 — the guarded order fulfilment workflows.
 *
 * Each transition commits its status change (plus delivery record, stock
 * restoration or transaction) and its ActivityLog row inside one DB
 * transaction, then queues OrderStatusUpdatedMail to the customer, store
 * owner and platform admin (deduplicated) after the transaction commits — a
 * mail failure must never leave the caller thinking a rolled-back transition
 * happened.
 *
 * The controllers keep the HTTP shape (source-state guards, refusal messages,
 * the 422 invalid-agent selection, the envelope); this service owns the
 * multi-table workflow and its transaction boundary.
 */
final class OrderFulfilmentService
{
    public function __construct(
        private readonly OrderFulfilmentRepository $repository,
        private readonly StockLedgerService $ledger,
    ) {}

    /**
     * Status change + activity row, commit-then-notify: the e-mail must never
     * leave the caller thinking a rolled-back transition happened.
     */
    public function transition(Order $order, OrderStatus $target, string $action, string $description, Request $request): void
    {
        $oldStatus = $order->status->value;

        DB::transaction(function () use ($request, $order, $target, $action, $description) {
            $order->update(['status' => $target]);
            $this->logActivity($request, $order, $action, $description);
        });

        $this->notifyOrderUpdate($order->fresh(), $oldStatus, $target->value);
    }

    public function dispatch(Order $order, array $data, ?User $agent, Request $request): void
    {
        $oldStatus = $order->status->value;

        DB::transaction(function () use ($request, $order, $data, $agent) {
            $order->update(['status' => OrderStatus::DISPATCHED]);

            // Stock was already reduced when the order was placed (checkout or
            // POS), so dispatch must not touch the ledger — the legacy modal's
            // "stock will be reduced" copy was simply wrong.
            //
            // Legacy validated `tracking_number` and then never wrote it to
            // the delivery row, and posted `delivery_agent_id` only to prefill
            // the driver fields. Both are persisted here.
            $delivery = new OrderDelivery([
                'order_id' => $order->id,
                'business_id' => $order->business_id,
                'status' => 'assigned',
                'delivery_route_id' => $order->delivery_route_id,
                'driver_name' => $data['driver_name'] ?? $agent?->name,
                'driver_phone' => $data['driver_phone'] ?? $agent?->phone,
                'tracking_number' => $data['tracking_number'] ?? null,
                'delivery_notes' => $data['delivery_notes'] ?? null,
                'estimated_delivery_at' => $data['estimated_delivery_at'] ?? null,
                'created_by' => $this->actor($request)->id,
            ]);
            $delivery->delivery_agent_id = $agent?->id;
            $delivery->save();

            $this->logActivity($request, $order, 'dispatched', 'Order dispatched for delivery');
        });

        $this->notifyOrderUpdate($order->fresh(), $oldStatus, OrderStatus::DISPATCHED->value);
    }

    public function deliver(Order $order, Request $request): void
    {
        $oldStatus = $order->status->value;

        DB::transaction(function () use ($request, $order) {
            $order->update(['status' => OrderStatus::DELIVERED]);

            $delivery = $order->delivery;

            if ($delivery) {
                $delivery->update([
                    'status' => 'delivered',
                    'actual_delivery_at' => now(),
                ]);
            }

            $this->logActivity($request, $order, 'delivered', 'Order delivered');
        });

        $this->notifyOrderUpdate($order->fresh(), $oldStatus, OrderStatus::DELIVERED->value);
    }

    public function cancel(Order $order, ?string $reason, Request $request): void
    {
        // Deliberate parity note: legacy never restored stock on cancel (only
        // on return), so neither does this. The stock was reduced at checkout
        // and a cancelled unpaid order is expected to release it manually.
        $oldStatus = $order->status->value;

        DB::transaction(function () use ($request, $order, $reason) {
            $order->update([
                'status' => OrderStatus::CANCELLED,
                'notes' => $order->notes
                    ? $order->notes."\nCancellation reason: ".($reason ?? 'No reason provided')
                    : 'Cancellation reason: '.($reason ?? 'No reason provided'),
            ]);

            $this->logActivity($request, $order, 'cancelled', $reason ?? 'Order cancelled');
        });

        $this->notifyOrderUpdate($order->fresh(), $oldStatus, OrderStatus::CANCELLED->value);
    }

    public function returnOrder(Order $order, ?string $reason, Request $request): void
    {
        $user = $this->actor($request);
        $oldStatus = $order->status->value;

        DB::transaction(function () use ($request, $order, $reason, $user) {
            $order->update(['status' => OrderStatus::RETURNED]);

            // Stock is restored for every line that has a stock location at
            // the order's store — deliberately with no digital filter: legacy
            // had none either (verified), so adding one would change behaviour.
            //
            // Quantities are summed per product first: recordAddition is
            // idempotent per (reference, location), so two lines for the same
            // product would silently restore only the first line's quantity.
            $restorable = $order->items
                ->filter(fn ($item) => $item->product_id !== null)
                ->groupBy('product_id')
                ->map(fn ($items) => (int) $items->sum('quantity'));

            foreach ($restorable as $productId => $quantity) {
                $stockLocation = $this->repository->stockLocationFor($order, $productId);

                if ($stockLocation) {
                    $this->ledger->recordAddition(
                        $stockLocation,
                        $quantity,
                        $order,
                        $user,
                        'Return — Order #'.$order->order_number
                    );
                }
            }

            $delivery = $order->delivery;

            if ($delivery) {
                $delivery->update([
                    'status' => 'returned',
                    'return_reason' => $reason,
                ]);
            }

            $this->logActivity($request, $order, 'returned', $reason ?? 'Order returned');
        });

        $this->notifyOrderUpdate($order->fresh(), $oldStatus, OrderStatus::RETURNED->value);
    }

    /**
     * Payment status control: pending / paid / refunded / failed / unpaid.
     *
     * No status mail is sent here — legacy sent none for a payment-status
     * change, and this workflow preserves that.
     */
    public function updatePaymentStatus(Order $order, string $requested, Request $request): void
    {
        $user = $this->actor($request);

        DB::transaction(function () use ($order, $requested, $user) {
            $transaction = $this->repository->latestTransaction($order);

            if ($requested === 'unpaid') {
                if (! $transaction) {
                    return;
                }

                // Legacy deleted the transaction outright, destroying the
                // audit trail. Void it instead — the row stays, flagged, and
                // the store balance is left untouched (legacy did not debit it
                // on delete either).
                $previousStatus = $transaction->status instanceof TransactionStatus
                    ? $transaction->status->value
                    : $transaction->status;

                $transaction->update([
                    'status' => TransactionStatus::CANCELED->value,
                    'paid_at' => null,
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'voided' => true,
                        'voided_at' => now()->toDateTimeString(),
                        'voided_by' => $user->id,
                        'voided_from' => $previousStatus,
                    ]),
                ]);

                return;
            }

            $mapped = match ($requested) {
                'pending' => TransactionStatus::PENDING,
                'paid' => TransactionStatus::CONFIRMED,
                'refunded' => TransactionStatus::REFUNDED,
                'failed' => TransactionStatus::CANCELED,
            };

            if ($transaction) {
                $transaction->update([
                    'status' => $mapped->value,
                    // paid_at now tracks the mapped status instead of being
                    // set for "paid" and left stale on every other value.
                    'paid_at' => match ($mapped) {
                        TransactionStatus::CONFIRMED => $transaction->paid_at ?? now(),
                        TransactionStatus::REFUNDED => $transaction->paid_at,
                        default => null,
                    },
                ]);

                return;
            }

            // Manual payment: legacy created it with the cash method, NGN and
            // a MAN- reference, but omitted payment_method_id and currency in
            // the new stack. Both are set here.
            $paymentMethod = $this->repository->manualPaymentMethod();

            Transaction::create([
                'reference' => 'MAN-'.strtoupper(Str::random(10)),
                'order_id' => $order->id,
                'business_id' => $order->business_id,
                'payment_method_id' => $paymentMethod?->id,
                'amount' => $order->total,
                'currency' => 'NGN',
                'status' => $mapped->value,
                'paid_at' => $mapped === TransactionStatus::CONFIRMED ? now() : null,
            ]);
        });
    }

    private function logActivity(Request $request, Order $order, string $action, string $description): void
    {
        ActivityLog::create([
            'user_id' => $this->actor($request)->id,
            'business_id' => $order->business_id,
            'action' => $action,
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'description' => $description,
            'ip_address' => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ]);
    }

    /**
     * Queue OrderStatusUpdatedMail to the customer, the store owner and the
     * platform admin. Identical addresses are collapsed (legacy deduplicated
     * by raw string; this compares case-insensitively), and a mail failure is
     * logged rather than failing the transition that already committed.
     */
    private function notifyOrderUpdate(Order $order, string $oldStatus, string $newStatus): void
    {
        $order->loadMissing(['customer', 'store.user']);

        $recipients = collect([
            $order->customer?->email,
            $order->store?->user?->email,
            config('mail.admin_email'),
        ])
            ->filter(fn ($email) => is_string($email) && $email !== '')
            ->map(fn (string $email) => strtolower($email))
            ->unique()
            ->values();

        try {
            foreach ($recipients as $email) {
                Mail::to($email)->queue(new OrderStatusUpdatedMail($order, $oldStatus, $newStatus));
            }

            Log::info('api.management.order_status_email_queued', [
                'order_id' => $order->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'recipients' => $recipients->all(),
            ]);
        } catch (\Throwable $e) {
            Log::error('api.management.order_status_email_failed', [
                'order_id' => $order->id,
                'new_status' => $newStatus,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
