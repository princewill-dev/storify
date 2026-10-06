<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Mail\OrderStatusUpdatedMail;
use App\Models\ActivityLog;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\OrderDelivery;
use App\Models\PaymentMethod;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StockLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * WS-12 — the guarded order fulfilment actions.
 *
 * Legacy exposed one free-form status setter plus a set of guarded POST
 * actions, each with side effects (activity log, status e-mail, delivery
 * record, stock restoration on return). Only the free-form setter was ported
 * to the new stack, which is why "accept" and friends live here rather than
 * on the read-oriented OrderController.
 *
 * Every transition runs in a DB transaction, writes an ActivityLog row and
 * queues OrderStatusUpdatedMail to the customer, store owner and platform
 * admin (deduplicated) after the transaction commits.
 */
class OrderFulfilmentController extends ApiController
{
    use ResolvesManagementContext;

    /**
     * The fulfilment console payload: everything the action bar, delivery
     * tracker, payment panel and activity timeline need in one call.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        return $this->ok(['order' => $this->context($order)]);
    }

    /**
     * Active Delivery Agents of this business — the dispatch modal's agent
     * picker source. Legacy supplied the same list from the show action.
     */
    public function deliveryAgents(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $agents = User::query()
            ->where('business_id', $order->business_id)
            ->where('status', 'active')
            ->role('Delivery Agent')
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'email'])
            ->map(fn (User $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'phone' => $agent->phone,
                'email' => $agent->email,
            ])->values()->all();

        return $this->ok(['delivery_agents' => $agents]);
    }

    public function accept(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if ($order->status !== OrderStatus::PENDING) {
            return $this->error('Only pending orders can be accepted.', 409);
        }

        $data = $request->validate([
            'override_pending_payment' => ['nullable', 'boolean'],
        ]);

        // Legacy enforced this with a modal only — the controller accepted the
        // order blind. Enforcing it server-side means the warning cannot be
        // bypassed by calling the endpoint directly; the override flag keeps
        // the deliberate "accept anyway" path available.
        $firstTransaction = $order->transactions()->orderBy('id')->first();

        if ($firstTransaction && $firstTransaction->status === TransactionStatus::PENDING
            && ! ($data['override_pending_payment'] ?? false)) {
            return $this->error(
                'This order has a payment still pending ('.$firstTransaction->reference.'). Confirm the payment first, or accept with an explicit override.',
                409,
            );
        }

        $this->applyStatus($request, $order, OrderStatus::ACCEPTED, 'accepted', 'Order accepted');

        return $this->ok(
            ['order' => $this->context($order->fresh())],
            'Order #'.$order->order_number.' has been accepted.',
        );
    }

    public function process(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if ($order->status !== OrderStatus::ACCEPTED) {
            return $this->error('Only accepted orders can be moved to processing.', 409);
        }

        $this->applyStatus($request, $order, OrderStatus::PROCESSING, 'processing', 'Order moved to processing');

        return $this->ok(
            ['order' => $this->context($order->fresh())],
            'Order #'.$order->order_number.' is now being processed.',
        );
    }

    public function dispatch(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if ($order->status !== OrderStatus::PROCESSING) {
            return $this->error('Only processing orders can be dispatched.', 409);
        }

        $data = $request->validate([
            'driver_name' => ['nullable', 'string', 'max:255'],
            'driver_phone' => ['nullable', 'string', 'max:20'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
            'delivery_notes' => ['nullable', 'string', 'max:500'],
            'estimated_delivery_at' => ['nullable', 'date'],
            'delivery_agent_id' => ['nullable', 'integer'],
        ]);

        $agent = null;

        if (! empty($data['delivery_agent_id'])) {
            $agent = $this->findDeliveryAgent($order, (int) $data['delivery_agent_id']);

            if (! $agent) {
                return $this->error('Select an active delivery agent from this business.', 422, [
                    'delivery_agent_id' => ['Select an active delivery agent from this business.'],
                ]);
            }
        }

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
                'created_by' => $this->user($request)->id,
            ]);
            $delivery->delivery_agent_id = $agent?->id;
            $delivery->save();

            $this->logActivity($request, $order, 'dispatched', 'Order dispatched for delivery');
        });

        $this->notifyOrderUpdate($order->fresh(), $oldStatus, OrderStatus::DISPATCHED->value);

        return $this->ok(
            ['order' => $this->context($order->fresh())],
            'Order #'.$order->order_number.' has been dispatched.',
        );
    }

    public function deliver(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if ($order->status !== OrderStatus::DISPATCHED) {
            return $this->error('Only dispatched orders can be marked as delivered.', 409);
        }

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

        return $this->ok(
            ['order' => $this->context($order->fresh())],
            'Order #'.$order->order_number.' marked as delivered.',
        );
    }

    public function complete(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if ($order->status !== OrderStatus::DELIVERED) {
            return $this->error('Only delivered orders can be completed.', 409);
        }

        $this->applyStatus($request, $order, OrderStatus::COMPLETED, 'completed', 'Order completed');

        return $this->ok(
            ['order' => $this->context($order->fresh())],
            'Order #'.$order->order_number.' has been completed.',
        );
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if (! in_array($order->status, [OrderStatus::PENDING, OrderStatus::ACCEPTED], true)) {
            return $this->error('Only pending or accepted orders can be cancelled.', 409);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = $data['reason'] ?? null;

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

        return $this->ok(
            ['order' => $this->context($order->fresh())],
            'Order #'.$order->order_number.' has been cancelled.',
        );
    }

    public function returnOrder(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if (! in_array($order->status, [OrderStatus::DELIVERED, OrderStatus::COMPLETED], true)) {
            return $this->error('Only delivered or completed orders can be returned.', 409);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = $data['reason'] ?? null;
        $user = $this->user($request);
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
            $ledger = app(StockLedgerService::class);

            $restorable = $order->items
                ->filter(fn ($item) => $item->product_id !== null)
                ->groupBy('product_id')
                ->map(fn ($items) => (int) $items->sum('quantity'));

            foreach ($restorable as $productId => $quantity) {
                $stockLocation = StockLocation::where('locationable_type', Store::class)
                    ->where('locationable_id', $order->store_id)
                    ->where('product_id', $productId)
                    ->first();

                if ($stockLocation) {
                    $ledger->recordAddition(
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

        return $this->ok(
            ['order' => $this->context($order->fresh())],
            'Order #'.$order->order_number.' has been returned. Stock restored.',
        );
    }

    /**
     * Payment status control: pending / paid / refunded / failed / unpaid.
     *
     * The shared route file still owns PUT orders/{order}/payment-status with
     * its four-status version; this endpoint lives on the legacy
     * orders/{order}/payment URI so it can extend the behaviour without
     * shadowing that route (Laravel matches the first-registered URI).
     */
    public function updatePaymentStatus(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $data = $request->validate([
            'payment_status' => ['required', Rule::in(['pending', 'paid', 'refunded', 'failed', 'unpaid'])],
        ]);

        $requested = $data['payment_status'];
        $user = $this->user($request);

        DB::transaction(function () use ($order, $requested, $user) {
            $transaction = $order->transactions()->orderByDesc('id')->first();

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
            $paymentMethod = PaymentMethod::where('code', 'cash')->first()
                ?? PaymentMethod::query()->first();

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

        return $this->ok(
            ['order' => $this->context($order->fresh())],
            'Payment status updated.',
        );
    }

    private function routeLabel(DeliveryRoute $route): ?string
    {
        $label = collect([$route->area, $route->state])->filter()->implode(', ');

        return $label !== '' ? $label : null;
    }

    private function findDeliveryAgent(Order $order, int $agentId): ?User
    {
        return User::query()
            ->whereKey($agentId)
            ->where('business_id', $order->business_id)
            ->where('status', 'active')
            ->role('Delivery Agent')
            ->first();
    }

    /**
     * Status change + activity row, commit-then-notify: the e-mail must never
     * leave the caller thinking a rolled-back transition happened.
     */
    private function applyStatus(Request $request, Order $order, OrderStatus $target, string $action, string $description): void
    {
        $oldStatus = $order->status->value;

        DB::transaction(function () use ($request, $order, $target, $action, $description) {
            $order->update(['status' => $target]);
            $this->logActivity($request, $order, $action, $description);
        });

        $this->notifyOrderUpdate($order->fresh(), $oldStatus, $target->value);
    }

    private function logActivity(Request $request, Order $order, string $action, string $description): void
    {
        ActivityLog::create([
            'user_id' => $this->user($request)->id,
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

    /**
     * @return array<string, mixed>
     */
    private function context(Order $order): array
    {
        $order->loadMissing([
            'items.product.images',
            'customer',
            'store',
            'delivery',
            'deliveryAddress',
            'deliveryRoute',
            'transactions.paymentMethod',
            'transactions.storeBank',
        ]);

        $status = $order->status instanceof OrderStatus ? $order->status->value : $order->status;

        // Legacy treated the first transaction as the payment gate ("Payment
        // Pending" modal); the newest one is only the target of payment-status
        // updates. Keep both semantics explicit.
        $firstTransaction = $order->transactions->sortBy('id')->first();
        $pendingTransaction = $firstTransaction && $firstTransaction->status === TransactionStatus::PENDING
            ? $firstTransaction
            : null;

        $delivery = $order->delivery;

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $status,
            'status_label' => $order->status_label,
            'payment_status' => $order->payment_status?->value,
            'source' => $order->source,
            'is_pos' => $order->isPos(),
            'store' => $order->store ? [
                'id' => $order->store->id,
                'name' => $order->store->name,
            ] : null,
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'name' => $order->customer->full_name,
                'email' => $order->customer->email,
                'phone' => $order->customer->phone,
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'product_code' => $item->product_code,
                'image_url' => $item->product?->primaryImage()
                    ? asset('storage/'.$item->product->primaryImage()->path)
                    : null,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'subtotal' => (float) $item->subtotal,
                'is_digital' => (bool) $item->is_digital,
            ])->values()->all(),
            'totals' => [
                'subtotal' => (float) $order->subtotal,
                'shipping_fee' => (float) $order->shipping_fee,
                'tax' => (float) $order->tax,
                'service_charge' => (float) ($order->service_charge_amount ?? 0),
                'total' => (float) $order->total,
                'amount_paid' => (float) $order->amount_paid,
                'remaining' => (float) $order->remainingBalance(),
            ],
            'notes' => $order->notes,
            'created_at' => $order->created_at?->toISOString(),
            'delivery_address' => $order->deliveryAddress ? [
                'name' => $order->deliveryAddress->recipient_name,
                'phone' => $order->deliveryAddress->recipient_phone,
                'street' => $order->deliveryAddress->street_address,
                'city' => $order->deliveryAddress->city,
                'state' => $order->deliveryAddress->state,
                'country' => $order->deliveryAddress->country,
            ] : null,
            'delivery_route' => $order->deliveryRoute ? [
                'id' => $order->deliveryRoute->id,
                'label' => $this->routeLabel($order->deliveryRoute),
                'fee' => (float) ($order->deliveryRoute->fee ?? 0),
            ] : null,
            'actions' => [
                'accept' => $status === OrderStatus::PENDING->value,
                'process' => $status === OrderStatus::ACCEPTED->value,
                'dispatch' => $status === OrderStatus::PROCESSING->value,
                'deliver' => $status === OrderStatus::DISPATCHED->value,
                'complete' => $status === OrderStatus::DELIVERED->value,
                'cancel' => in_array($status, [OrderStatus::PENDING->value, OrderStatus::ACCEPTED->value], true),
                'return' => in_array($status, [OrderStatus::DELIVERED->value, OrderStatus::COMPLETED->value], true),
            ],
            'payment_warning' => $pendingTransaction ? [
                'message' => 'This order has a payment still pending. Confirm it before accepting, or accept with an explicit override.',
                'transaction' => $this->transactionPayload($pendingTransaction, detailed: false),
            ] : null,
            'delivery' => $delivery ? [
                'id' => $delivery->id,
                'status' => $delivery->status,
                'tracking_number' => $delivery->tracking_number,
                'driver_name' => $delivery->driver_name,
                'driver_phone' => $delivery->driver_phone,
                'delivery_agent_id' => $delivery->delivery_agent_id !== null
                    ? (int) $delivery->delivery_agent_id
                    : null,
                'delivery_agent' => $delivery->delivery_agent_id
                    ? User::whereKey($delivery->delivery_agent_id)->value('name')
                    : null,
                'route' => $delivery->deliveryRoute ? $this->routeLabel($delivery->deliveryRoute) : null,
                'estimated_delivery_at' => $delivery->estimated_delivery_at?->toISOString(),
                'actual_delivery_at' => $delivery->actual_delivery_at?->toISOString(),
                'delivery_notes' => $delivery->delivery_notes,
                'return_reason' => $delivery->return_reason,
            ] : null,
            'transactions' => $order->transactions->sortBy('id')->values()
                ->map(fn (Transaction $transaction) => $this->transactionPayload($transaction, detailed: true))
                ->all(),
            'activity' => ActivityLog::query()
                ->where('subject_type', Order::class)
                ->where('subject_id', $order->id)
                ->with('user:id,name')
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (ActivityLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'description' => $log->description,
                    'user' => $log->user?->name,
                    'created_at' => $log->created_at?->toISOString(),
                ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionPayload(Transaction $transaction, bool $detailed): array
    {
        $data = [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'status' => $transaction->status instanceof TransactionStatus
                ? $transaction->status->value
                : $transaction->status,
            'payment_method' => $transaction->paymentMethod?->name,
            'paid_at' => $transaction->paid_at?->toISOString(),
        ];

        if ($detailed) {
            // Bank proof-of-payment panel: staff match the transfer against
            // the receiving account (legacy's "Bank Account" card).
            $data['bank'] = $transaction->storeBank ? [
                'bank_name' => $transaction->storeBank->bank_name,
                'account_number' => $transaction->storeBank->account_number,
                'account_name' => $transaction->storeBank->account_name,
                'is_verified' => (bool) $transaction->storeBank->is_verified,
            ] : null;
        }

        return $data;
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $user = $this->user($request);

        if ((int) $order->business_id !== (int) $user->business_id
            || ! $user->accessibleStores()->whereKey($order->store_id)->exists()) {
            abort(403, 'You do not have access to this order.');
        }
    }
}
