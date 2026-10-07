<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\Admin\Concerns\SerializesAdminOrders;
use App\Mail\CustomerOrderStatusUpdatedMail;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * WS-5 — the platform order-oversight write workflows.
 *
 * The edit's fee/total recompute, the status transition (order update, dated
 * note append, audit row and customer email) and the payment-status override
 * (the transaction sync, all inside one transaction) live here with the exact
 * sequence the controller used. Every write pairs with its ActivityRecorder
 * row in the same transaction, so a failed audit cannot silently drop.
 *
 * The edit keeps its boundary with the caller on purpose: its audit row is
 * built from the shared admin-order serializer's snapshot
 * ({@see SerializesAdminOrders::orderAuditSnapshot()}),
 * which the Shop4Me queue renders from as well — moving the transaction in
 * here would fork that contract. The transition inside the edit therefore
 * runs in the caller's transaction (one flat transaction per flow, exactly
 * as before). The delete stays in the controller: its "old" payload is that
 * same shared snapshot and a service method would only wrap the soft delete.
 */
final class OrderOversightService
{
    /**
     * The edit form's write: shipping fee, tax, the recomputed total, notes
     * and the optional status transition.
     *
     * No transaction of its own — the caller wraps it together with the
     * edit's audit row, keeping the single boundary the controller always
     * used. The optional transition below runs in that same transaction.
     *
     * @param  array<string, mixed>  $data  the validated edit payload
     */
    public function applyEdit(Order $order, array $data, User $actor): void
    {
        $shippingFee = array_key_exists('shipping_fee', $data)
            ? round((float) $data['shipping_fee'], 2)
            : (float) $order->shipping_fee;
        $tax = array_key_exists('tax', $data)
            ? round((float) $data['tax'], 2)
            : (float) $order->tax;

        // Recomputed from the persisted parts so editing a fee cannot leave
        // the stored total — and every payment badge derived from it — out of
        // step. Service charge is the one component this form must not touch.
        $total = round(
            (float) $order->subtotal + $shippingFee + $tax + (float) ($order->service_charge_amount ?? 0),
            2,
        );

        $order->update([
            'shipping_fee' => $shippingFee,
            'tax' => $tax,
            'total' => $total,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $order->notes,
        ]);

        // A status posted through the edit form takes the same transition
        // path (audit + customer email) as the status card, so the email
        // still fires exactly once per real transition. It runs in the
        // caller's transaction, exactly where the flat edit transaction
        // always carried it.
        if (isset($data['status'])) {
            $this->applyTransition($order, $data['status'], null, $actor);
        }
    }

    /**
     * The standalone status card's entry point: one transition inside its own
     * transaction. Returns whether the customer was emailed. The controller
     * guards against same-status saves, so the email fires exactly once per
     * real transition.
     */
    public function transitionStatus(Order $order, string $newStatus, ?string $note, User $actor): bool
    {
        return DB::transaction(fn (): bool => $this->applyTransition($order, $newStatus, $note, $actor));
    }

    /**
     * One status transition: the order update, the dated note append, the
     * audit row and the customer email. Runs inside the caller's transaction
     * (see {@see applyEdit()} and {@see transitionStatus()}). Returns whether
     * the customer was emailed.
     */
    private function applyTransition(Order $order, string $newStatus, ?string $note, User $actor): bool
    {
        $oldStatus = $order->status instanceof OrderStatus ? $order->status->value : (string) $order->status;

        if ($oldStatus === $newStatus) {
            return false;
        }

        $order->update(['status' => $newStatus]);

        if ($note !== null && $note !== '') {
            $currentNotes = $order->notes ? $order->notes."\n\n" : '';
            $order->update([
                'notes' => $currentNotes.'['.now()->format('Y-m-d H:i').'] Status changed to '.$newStatus.': '.$note,
            ]);
        }

        ActivityRecorder::record(
            action: 'status_updated',
            description: "Changed order status from {$oldStatus} to {$newStatus}",
            subject: $order,
            old: ['status' => $oldStatus],
            new: ['status' => $newStatus],
            metadata: $note !== null && $note !== '' ? ['note' => $note] : [],
            actor: $actor,
        );

        return $this->notifyCustomerOfStatusChange($order, $oldStatus, $newStatus);
    }

    /**
     * The payment-status override maps onto the order's transactions, all
     * inside one transaction: unpaid clears the recorded legs, the other
     * three states update the latest leg or create a manual `MAN-…` row
     * through the cash method (legacy's fallback chain).
     *
     * The "before" state arrives from the caller: it is read from the shared
     * admin-order serializer's derived badge
     * ({@see SerializesAdminOrders::derivedPaymentStatus()}),
     * which stays with the serializer both order screens read.
     */
    public function updatePaymentStatus(
        Order $order,
        string $paymentStatus,
        string $previousPaymentStatus,
        User $actor,
    ): void {
        DB::transaction(function () use ($order, $paymentStatus, $previousPaymentStatus, $actor) {
            if ($paymentStatus === 'unpaid') {
                // Clears every recorded leg, not just the latest one: leaving
                // a paid leg behind would keep the derived badge "partially
                // paid" after the admin marked the order unpaid.
                $order->transactions()->delete();
            } else {
                $status = match ($paymentStatus) {
                    'paid' => TransactionStatus::CONFIRMED,
                    'refunded' => TransactionStatus::REFUNDED,
                    'failed' => TransactionStatus::CANCELED,
                };

                $transaction = $order->transactions()->latest('id')->first();

                if ($transaction) {
                    $transaction->update([
                        'status' => $status,
                        'paid_at' => $status === TransactionStatus::CONFIRMED
                            ? ($transaction->paid_at ?? now())
                            : $transaction->paid_at,
                    ]);
                } else {
                    $paymentMethod = PaymentMethod::where('code', 'cash')->first()
                        ?? PaymentMethod::orderBy('id')->first();

                    $order->transactions()->create([
                        'business_id' => $order->business_id,
                        'payment_method_id' => $paymentMethod?->id,
                        'reference' => 'MAN-'.strtoupper(Str::random(10)),
                        'amount' => $order->total,
                        'currency' => $order->transactions()->orderByDesc('id')->value('currency') ?? 'NGN',
                        'status' => $status,
                        'paid_at' => $status === TransactionStatus::CONFIRMED ? now() : null,
                    ]);
                }
            }

            ActivityRecorder::record(
                action: 'payment_status_updated',
                description: 'Changed payment status to '.$paymentStatus,
                subject: $order,
                old: ['payment_status' => $previousPaymentStatus],
                new: ['payment_status' => $paymentStatus],
                actor: $actor,
            );
        });
    }

    private function notifyCustomerOfStatusChange(Order $order, string $oldStatus, string $newStatus): bool
    {
        $email = $order->customer?->email;

        // POS/walk-in orders have no customer email; legacy assumed one and
        // crashed the whole transition on them.
        if (! $email) {
            Log::info('customer_order_status_email_skipped', [
                'order_id' => $order->id,
                'reason' => 'no customer email',
            ]);

            return false;
        }

        try {
            Mail::to($email)->send(new CustomerOrderStatusUpdatedMail($order->fresh(), $oldStatus, $newStatus));

            Log::info('customer_order_status_email_sent', [
                'order_id' => $order->id,
                'customer_email' => $email,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            return true;
        } catch (\Throwable $e) {
            // The notification must never fail the transition itself.
            Log::error('customer_order_status_email_failed', [
                'order_id' => $order->id,
                'customer_email' => $email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
