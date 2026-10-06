<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\OrderFulfilmentAcceptRequest;
use App\Http\Requests\Management\OrderFulfilmentCancelRequest;
use App\Http\Requests\Management\OrderFulfilmentDispatchRequest;
use App\Http\Requests\Management\OrderFulfilmentPaymentStatusRequest;
use App\Http\Requests\Management\OrderFulfilmentReturnRequest;
use App\Http\Resources\Management\FulfilmentDeliveryAgentResource;
use App\Http\Resources\Management\OrderFulfilmentResource;
use App\Models\Order;
use App\Repositories\Management\OrderFulfilmentRepository;
use App\Services\Access\TenantGuard;
use App\Services\Management\OrderFulfilmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        if ($order->status !== OrderStatus::PENDING) {
            return $this->error('Only pending orders can be accepted.', 409);
        }

        $data = $request->validated();

        // Legacy enforced this with a modal only — the controller accepted the
        // order blind. Enforcing it server-side means the warning cannot be
        // bypassed by calling the endpoint directly; the override flag keeps
        // the deliberate "accept anyway" path available.
        $firstTransaction = $this->repository->firstTransaction($order);

        if ($firstTransaction && $firstTransaction->status === TransactionStatus::PENDING
            && ! ($data['override_pending_payment'] ?? false)) {
            return $this->error(
                'This order has a payment still pending ('.$firstTransaction->reference.'). Confirm the payment first, or accept with an explicit override.',
                409,
            );
        }

        $this->service->transition($order, OrderStatus::ACCEPTED, 'accepted', 'Order accepted', $request);

        return $this->ok(
            ['order' => $this->console($order->fresh())],
            'Order #'.$order->order_number.' has been accepted.',
        );
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
