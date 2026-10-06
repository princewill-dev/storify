<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\Admin\Concerns\SerializesAdminOrders;
use App\Http\Controllers\Api\V1\ApiController;
use App\Mail\CustomerOrderStatusUpdatedMail;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * WS-5 — platform order oversight (admin console).
 *
 * The admin token sees the whole platform by design; `authorizePlatformAdmin`
 * closes the hole where a business-scoped account could hold a leaked
 * admin-audience token with the bundled admin.* permissions. Every mutation
 * runs in a transaction and writes an ActivityRecorder row (WS-1), so a failed
 * audit cannot silently drop with it.
 *
 * Deliberately fixed rather than cloned from legacy: the raw sort_by passed to
 * orderBy, the model-level no-op payment_status select on the edit form, the
 * "cannot be undone" delete copy, and the Shop4Me payment badge that never
 * matched its enum.
 */
class OrderController extends ApiController
{
    use EnsuresPlatformAdmin;
    use SerializesAdminOrders;

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $this->orderListFilters($request);
        $result = $this->paginateOrderIndex(Order::query(), $filters);

        return $this->ok(
            ['orders' => $result['orders']],
            null,
            200,
            $this->paginationMeta($result['paginator']) + ['stats' => $result['stats']],
        );
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->ok(['order' => $this->orderDetail($order)]);
    }

    /**
     * The edit form's real surface: shipping fee, tax, status and notes. The
     * customer/delivery inputs legacy rendered were never validated or
     * fillable, and its payment_status select was a model-level no-op — none
     * of them are rebuilt here.
     */
    public function update(Request $request, Order $order): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'shipping_fee' => ['sometimes', 'numeric', 'min:0'],
            'tax' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $order, $data) {
            $before = $this->orderAuditSnapshot($order);

            $shippingFee = array_key_exists('shipping_fee', $data)
                ? round((float) $data['shipping_fee'], 2)
                : (float) $order->shipping_fee;
            $tax = array_key_exists('tax', $data)
                ? round((float) $data['tax'], 2)
                : (float) $order->tax;

            // Recomputed from the persisted parts so editing a fee cannot
            // leave the stored total — and every payment badge derived from it
            // — out of step. Service charge is the one component this form
            // must not touch.
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

            // A status posted through this form takes the same transition
            // path (audit + customer email) as the status card, so the email
            // still fires exactly once per real transition.
            if (isset($data['status'])) {
                $this->transitionStatus($order, $data['status'], null, $request);
            }

            ActivityRecorder::record(
                action: 'updated',
                description: 'Updated order #'.$order->order_number,
                subject: $order,
                old: $before,
                new: $this->orderAuditSnapshot($order->fresh()),
                actor: $request->user(),
            );
        });

        return $this->ok(['order' => $this->orderDetail($order->fresh())], 'Order updated.');
    }

    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validate([
            'status' => ['required', Rule::in(array_column(OrderStatus::cases(), 'value'))],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $current = $order->status instanceof OrderStatus ? $order->status->value : (string) $order->status;

        if ($current === $data['status']) {
            return $this->ok(['order' => $this->orderDetail($order)], 'Order is already '.$data['status'].'.');
        }

        $notified = DB::transaction(fn (): bool => $this->transitionStatus($order, $data['status'], $data['notes'] ?? null, $request));

        return $this->ok(
            ['order' => $this->orderDetail($order->fresh())],
            "Order status updated to {$data['status']}."
                .($notified
                    ? ' Customer has been notified via email.'
                    : ' No customer email is on file, so no notification was sent.'),
        );
    }

    /**
     * The payment-status override maps onto the order's transactions, all
     * inside one transaction: unpaid clears the recorded legs, the other three
     * states update the latest leg or create a manual `MAN-…` row through the
     * cash method (legacy's fallback chain).
     */
    public function updatePaymentStatus(Request $request, Order $order): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // Legacy validated `required|string` and quietly defaulted unknown
        // values to pending; only the four transitions its UI exposed are
        // accepted.
        $data = $request->validate([
            'payment_status' => ['required', Rule::in(['unpaid', 'paid', 'refunded', 'failed'])],
        ]);

        $previous = $this->derivedPaymentStatus($order)->value;

        DB::transaction(function () use ($request, $order, $data, $previous) {
            if ($data['payment_status'] === 'unpaid') {
                // Clears every recorded leg, not just the latest one: leaving
                // a paid leg behind would keep the derived badge "partially
                // paid" after the admin marked the order unpaid.
                $order->transactions()->delete();
            } else {
                $status = match ($data['payment_status']) {
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
                description: 'Changed payment status to '.$data['payment_status'],
                subject: $order,
                old: ['payment_status' => $previous],
                new: ['payment_status' => $data['payment_status']],
                actor: $request->user(),
            );
        });

        return $this->ok(
            ['order' => $this->orderDetail($order->fresh())],
            'Payment status updated to '.$data['payment_status'].'.',
        );
    }

    /**
     * Soft delete only — the row (and its items and transactions) is retained
     * for refunds and audit, and nothing cascades. Legacy promised the
     * opposite in its confirm copy.
     */
    public function destroy(Request $request, Order $order): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $orderNumber = $order->order_number;

        DB::transaction(function () use ($request, $order) {
            ActivityRecorder::record(
                action: 'deleted',
                description: 'Deleted order #'.$order->order_number,
                subject: $order,
                old: $this->orderAuditSnapshot($order),
                actor: $request->user(),
            );

            $order->delete();
        });

        return $this->ok([], "Order #{$orderNumber} deleted.");
    }

    /**
     * Applies one status transition: the order update, the dated note append,
     * the audit row and the customer email. Returns whether the customer was
     * emailed. Callers guard against same-status saves, so the email fires
     * exactly once per real transition.
     */
    private function transitionStatus(Order $order, string $newStatus, ?string $note, Request $request): bool
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
            actor: $request->user(),
        );

        return $this->notifyCustomerOfStatusChange($order, $oldStatus, $newStatus);
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
