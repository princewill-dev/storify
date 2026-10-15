<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\BulkOrderActionRequest;
use App\Http\Requests\Management\OrderFulfilmentAcceptRequest;
use App\Http\Requests\Management\OrderFulfilmentCancelRequest;
use App\Http\Requests\Management\OrderFulfilmentDispatchRequest;
use App\Http\Requests\Management\OrderFulfilmentPaymentStatusRequest;
use App\Http\Requests\Management\OrderFulfilmentReturnRequest;
use App\Http\Resources\Management\FulfilmentDeliveryAgentResource;
use App\Http\Resources\Management\OrderFulfilmentResource;
use App\Models\Order;
use App\Models\User;
use App\Repositories\Management\OrderFulfilmentRepository;
use App\Services\Access\TenantGuard;
use App\Services\Management\OrderFulfilmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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
 * admin (deduplicated) after the transaction commits. The workflows now live
 * in OrderFulfilmentService, the reads in OrderFulfilmentRepository and the
 * console payload in OrderFulfilmentResource; this controller keeps the HTTP
 * contract — source-state guards, refusal messages, status codes and the
 * envelope.
 */
class OrderFulfilmentController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly OrderFulfilmentRepository $repository,
        private readonly OrderFulfilmentService $service,
        private readonly TenantGuard $tenantGuard,
    ) {}

    /**
     * The fulfilment console payload: everything the action bar, delivery
     * tracker, payment panel and activity timeline need in one call.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        return $this->ok(['order' => $this->console($order)]);
    }

    /**
     * Active Delivery Agents of this business — the dispatch modal's agent
     * picker source. Legacy supplied the same list from the show action.
     */
    public function deliveryAgents(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        return $this->ok([
            'delivery_agents' => FulfilmentDeliveryAgentResource::collection(
                $this->repository->activeDeliveryAgents($order)
            )->resolve(),
        ]);
    }

    public function accept(OrderFulfilmentAcceptRequest $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $data = $request->validated();

        // Legacy enforced this with a modal only — the controller accepted the
        // order blind. Enforcing it server-side means the warning cannot be
        // bypassed by calling the endpoint directly; the override flag keeps
        // the deliberate "accept anyway" path available.
        $refusal = $this->acceptRefusal($order, (bool) ($data['override_pending_payment'] ?? false));

        if ($refusal !== null) {
            return $this->error($refusal, 409);
        }

        $this->service->transition($order, OrderStatus::ACCEPTED, 'accepted', 'Order accepted', $request);

        return $this->ok(
            ['order' => $this->console($order->fresh())],
            'Order #'.$order->order_number.' has been accepted.',
        );
    }

    /**
     * Why this order cannot be accepted, or null if it can.
     *
     * Extracted so the single-order action and the bulk action cannot disagree
     * about the rule. The bulk passes `false` for the override, always: an
     * override is a person looking at one order and deciding to accept it
     * anyway, and there is no way to mean that about forty at once.
     */
    private function acceptRefusal(Order $order, bool $override): ?string
    {
        if ($order->status !== OrderStatus::PENDING) {
            return 'Only pending orders can be accepted.';
        }

        $firstTransaction = $this->repository->firstTransaction($order);

        if ($firstTransaction && $firstTransaction->status === TransactionStatus::PENDING && ! $override) {
            return 'This order has a payment still pending ('.$firstTransaction->reference.'). Confirm the payment first, or accept with an explicit override.';
        }

        return null;
    }

    public function process(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if ($order->status !== OrderStatus::ACCEPTED) {
            return $this->error('Only accepted orders can be moved to processing.', 409);
        }

        $this->service->transition($order, OrderStatus::PROCESSING, 'processing', 'Order moved to processing', $request);

        return $this->ok(
            ['order' => $this->console($order->fresh())],
            'Order #'.$order->order_number.' is now being processed.',
        );
    }

    public function dispatch(OrderFulfilmentDispatchRequest $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if ($order->status !== OrderStatus::PROCESSING) {
            return $this->error('Only processing orders can be dispatched.', 409);
        }

        $data = $request->validated();
        $agent = null;

        if (! empty($data['delivery_agent_id'])) {
            $agent = $this->repository->findDeliveryAgent($order, (int) $data['delivery_agent_id']);

            if (! $agent) {
                return $this->error('Select an active delivery agent from this business.', 422, [
                    'delivery_agent_id' => ['Select an active delivery agent from this business.'],
                ]);
            }
        }

        $this->service->dispatch($order, $data, $agent, $request);

        return $this->ok(
            ['order' => $this->console($order->fresh())],
            'Order #'.$order->order_number.' has been dispatched.',
        );
    }

    public function deliver(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if ($order->status !== OrderStatus::DISPATCHED) {
            return $this->error('Only dispatched orders can be marked as delivered.', 409);
        }

        $this->service->deliver($order, $request);

        return $this->ok(
            ['order' => $this->console($order->fresh())],
            'Order #'.$order->order_number.' marked as delivered.',
        );
    }

    public function complete(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if ($order->status !== OrderStatus::DELIVERED) {
            return $this->error('Only delivered orders can be completed.', 409);
        }

        $this->service->transition($order, OrderStatus::COMPLETED, 'completed', 'Order completed', $request);

        return $this->ok(
            ['order' => $this->console($order->fresh())],
            'Order #'.$order->order_number.' has been completed.',
        );
    }

    public function cancel(OrderFulfilmentCancelRequest $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if (! in_array($order->status, [OrderStatus::PENDING, OrderStatus::ACCEPTED], true)) {
            return $this->error('Only pending or accepted orders can be cancelled.', 409);
        }

        $data = $request->validated();

        $this->service->cancel($order, $data['reason'] ?? null, $request);

        return $this->ok(
            ['order' => $this->console($order->fresh())],
            'Order #'.$order->order_number.' has been cancelled.',
        );
    }

    public function returnOrder(OrderFulfilmentReturnRequest $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        if (! in_array($order->status, [OrderStatus::DELIVERED, OrderStatus::COMPLETED], true)) {
            return $this->error('Only delivered or completed orders can be returned.', 409);
        }

        $data = $request->validated();

        $this->service->returnOrder($order, $data['reason'] ?? null, $request);

        return $this->ok(
            ['order' => $this->console($order->fresh())],
            'Order #'.$order->order_number.' has been returned. Stock restored.',
        );
    }

    /**
     * POST /management/orders/bulk-accept — accept the selected orders.
     *
     * Per order, not per batch. An order that is already accepted, or that has
     * a payment still pending, is skipped with its reason rather than failing
     * the whole selection: a queue of forty where three need attention should
     * move thirty-seven and name the three.
     */
    public function bulkAccept(BulkOrderActionRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $ids = $request->orderIds();
        $orders = $this->scopedOrders($user, $ids);

        $accepted = [];
        $skipped = [];

        foreach ($ids as $id) {
            $order = $orders->get($id);

            if (! $order) {
                $skipped[$id] = 'Not found.';

                continue;
            }

            $refusal = $this->acceptRefusal($order, false);

            if ($refusal !== null) {
                $skipped[$id] = $refusal;

                continue;
            }

            $this->service->transition($order, OrderStatus::ACCEPTED, 'accepted', 'Order accepted', $request);
            $accepted[] = $id;
        }

        return $this->ok(
            ['accepted' => count($accepted), 'accepted_ids' => $accepted, 'skipped' => $skipped, 'skipped_ids' => array_keys($skipped)],
            $this->batchMessage('accepted', count($accepted), count($skipped)),
        );
    }

    /**
     * POST /management/orders/bulk-cancel — cancel the selected orders.
     *
     * The same per-order contract and the same source-state guard the single
     * action applies: only pending or accepted orders can be cancelled.
     */
    public function bulkCancel(BulkOrderActionRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $ids = $request->orderIds();
        $orders = $this->scopedOrders($user, $ids);
        $reason = $request->reason();

        $cancelled = [];
        $skipped = [];

        foreach ($ids as $id) {
            $order = $orders->get($id);

            if (! $order) {
                $skipped[$id] = 'Not found.';

                continue;
            }

            if (! in_array($order->status, [OrderStatus::PENDING, OrderStatus::ACCEPTED], true)) {
                $skipped[$id] = 'Only pending or accepted orders can be cancelled.';

                continue;
            }

            $this->service->cancel($order, $reason, $request);
            $cancelled[] = $id;
        }

        return $this->ok(
            ['cancelled' => count($cancelled), 'cancelled_ids' => $cancelled, 'skipped' => $skipped, 'skipped_ids' => array_keys($skipped)],
            $this->batchMessage('cancelled', count($cancelled), count($skipped)),
        );
    }

    /**
     * The orders a bulk action may touch, keyed by id.
     *
     * Read through the same two rules {@see authorizeOrder} applies one at a
     * time — the caller's business, and the stores the caller can reach. An id
     * outside either is simply absent, so it is reported as skipped rather
     * than distinguished, and this cannot be used to probe for other
     * businesses' order ids.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, Order>
     */
    private function scopedOrders(User $user, Collection $ids): Collection
    {
        return Order::query()
            ->whereIn('id', $ids)
            ->where('business_id', $user->business_id)
            ->whereIn('store_id', $user->accessibleStoreIds())
            ->get()
            ->keyBy('id');
    }

    /**
     * "12 order(s) accepted." or "10 order(s) accepted, 2 skipped."
     *
     * The skipped count is in the sentence because a batch that quietly did
     * less than it was asked to is the failure the per-order contract exists
     * to avoid, and a bare "ok" over it would put it back.
     */
    private function batchMessage(string $verb, int $done, int $skipped): string
    {
        $message = $done.' order(s) '.$verb.'.';

        return $skipped > 0 ? $message.' '.$skipped.' skipped.' : $message;
    }

    /**
     * Payment status control: pending / paid / refunded / failed / unpaid.
     *
     * The shared route file still owns PUT orders/{order}/payment-status with
     * its four-status version; this endpoint lives on the legacy
     * orders/{order}/payment URI so it can extend the behaviour without
     * shadowing that route (Laravel matches the first-registered URI).
     */
    public function updatePaymentStatus(OrderFulfilmentPaymentStatusRequest $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $data = $request->validated();

        $this->service->updatePaymentStatus($order, $data['payment_status'], $request);

        return $this->ok(
            ['order' => $this->console($order->fresh())],
            'Payment status updated.',
        );
    }

    /**
     * Response shaping for every action. The relations are eager-loaded by the
     * repository; pass `$order->fresh()` after a transition so the payload
     * reflects the committed state.
     */
    private function console(Order $order): OrderFulfilmentResource
    {
        return new OrderFulfilmentResource(
            $this->repository->loadConsole($order),
            $this->repository->recentActivity($order),
        );
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $this->tenantGuard->authorizeBusinessAndStore(
            $order,
            $this->user($request),
            (int) $order->store_id,
            'You do not have access to this order.',
        );
    }
}
