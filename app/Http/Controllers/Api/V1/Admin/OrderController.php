<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\Admin\Concerns\SerializesAdminOrders;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\UpdateOrderPaymentStatusRequest;
use App\Http\Requests\Admin\UpdateOrderRequest;
use App\Http\Requests\Admin\UpdateOrderStatusRequest;
use App\Models\Order;
use App\Services\ActivityRecorder;
use App\Services\Admin\OrderOversightService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
 *
 * Layer split: validation lives in the Admin FormRequests, and the mutation
 * workflows — the edit's fee/total recompute, the status transition (audit +
 * customer email) and the transaction-syncing payment override — in
 * OrderOversightService. The list filters, queries, stats and every payload
 * shape deliberately stay in the shared {@see SerializesAdminOrders} concern,
 * which the Shop4Me queue renders from too; pulling a method out would fork
 * that serializer. The edit and delete therefore keep their transaction and
 * their audit rows here, around the service calls, because those audit
 * payloads are that shared snapshot.
 */
class OrderController extends ApiController
{
    use EnsuresPlatformAdmin;
    use SerializesAdminOrders;

    public function __construct(
        private readonly OrderOversightService $orders,
    ) {}

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
     *
     * One controller-owned transaction keeps the audit row (whose payload is
     * the shared SerializesAdminOrders snapshot) rolled back with the write.
     */
    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();

        DB::transaction(function () use ($request, $order, $data) {
            $before = $this->orderAuditSnapshot($order);

            $this->orders->applyEdit($order, $data, $request->user());

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

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();

        $current = $order->status instanceof OrderStatus ? $order->status->value : (string) $order->status;

        if ($current === $data['status']) {
            return $this->ok(['order' => $this->orderDetail($order)], 'Order is already '.$data['status'].'.');
        }

        $notified = $this->orders->transitionStatus($order, $data['status'], $data['notes'] ?? null, $request->user());

        return $this->ok(
            ['order' => $this->orderDetail($order->fresh())],
            "Order status updated to {$data['status']}."
                .($notified
                    ? ' Customer has been notified via email.'
                    : ' No customer email is on file, so no notification was sent.'),
        );
    }

    /**
     * The payment-status override. The workflow, its transaction boundary and
     * its audit row live in OrderOversightService; the "before" badge is read
     * here, from the shared serializer, at the same point in the sequence as
     * before.
     */
    public function updatePaymentStatus(UpdateOrderPaymentStatusRequest $request, Order $order): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();

        $previous = $this->derivedPaymentStatus($order)->value;

        $this->orders->updatePaymentStatus(
            $order,
            $data['payment_status'],
            $previous,
            $request->user(),
        );

        return $this->ok(
            ['order' => $this->orderDetail($order->fresh())],
            'Payment status updated to '.$data['payment_status'].'.',
        );
    }

    /**
     * Soft delete only — the row (and its items and transactions) is retained
     * for refunds and audit, and nothing cascades. Legacy promised the
     * opposite in its confirm copy.
     *
     * Kept here with the edit: its "old" payload is the shared serializer's
     * snapshot, and a service method would only wrap the soft delete.
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
}
