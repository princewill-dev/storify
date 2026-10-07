<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Pos\RefundOrderRequest;
use App\Http\Resources\Pos\OrderHistoryResource;
use App\Http\Resources\Pos\OrderReceiptResource;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Repositories\Pos\OrderRepository;
use App\Services\Pos\OrderRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The POS orders screens — the cashier's history, one order's receipt and the
 * refund request.
 *
 * Layering: only the HTTP shape stays here — the `success` envelope, status
 * codes, message strings and pagination meta. The history query (including the
 * session-vs-staff scoping) is OrderRepository::paginateHistory(); the refund's
 * transaction boundary, row write and post-commit log are
 * OrderRefundService::requestRefund(); the payload rules are
 * RefundOrderRequest; the row shapes are OrderHistoryResource and
 * OrderReceiptResource.
 *
 * The receipt query deliberately stays inline: it is a single store-scoped
 * findOrFail with its eager loads and one call site, so a repository method
 * would be indirection with no benefit.
 *
 * The store these endpoints are aimed at is authorised by the
 * EnsurePosStoreAccess route middleware (403) before the controller body runs —
 * the guard deliberately stays out of these classes.
 */
final class OrderController extends ApiController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly OrderRefundService $refunds,
    ) {}

    public function history(Request $request, Store $store): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $orders = $this->orders->paginateHistory(
            $store,
            $user,
            $request->filled('q') ? $request->string('q')->toString() : null,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'orders' => OrderHistoryResource::collection($orders->getCollection())->resolve($request),
                'pagination' => $this->paginationMeta($orders),
            ],
        ]);
    }

    public function receipt(Request $request, Store $store, int $orderId): JsonResponse
    {
        $order = Order::query()
            ->where('store_id', $store->id)
            ->with(['items', 'transactions.paymentMethod', 'customer'])
            ->findOrFail($orderId);

        return response()->json([
            'success' => true,
            'data' => [
                'order' => (new OrderReceiptResource($order, $store))->resolve($request),
            ],
        ]);
    }

    public function refund(RefundOrderRequest $request, Store $store, int $orderId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->refunds->requestRefund($store, $orderId, $user, $request->validated('reason'));

        if ($result === 'not_confirmed') {
            return response()->json(['success' => false, 'message' => 'Only confirmed orders can be refunded.'], 400);
        }

        if ($result === 'duplicate') {
            return response()->json(['success' => false, 'message' => 'A refund has already been requested for this order.'], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Refund requested. Awaiting admin approval.',
        ], 201);
    }
}
