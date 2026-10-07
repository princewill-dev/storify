<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\UpdateOrderPaymentStatusRequest;
use App\Http\Requests\Management\UpdateOrderStatusRequest;
use App\Http\Resources\Management\OrderDetailResource;
use App\Http\Resources\Management\OrderResource;
use App\Models\Order;
use App\Models\Transaction;
use App\Repositories\Management\OrderRepository;
use App\Services\Access\TenantGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The base management orders API — list, show, the status and payment-status
 * setters, and delete.
 *
 * Layering: the HTTP shape (status codes, message strings, the envelope,
 * pagination meta) stays here; the list query and the show screen's eager
 * loads live in OrderRepository; the row shapes in OrderResource (list) and
 * OrderDetailResource (show); the two payload rule sets in their
 * FormRequests; the tenant check in TenantGuard.
 *
 * No service on purpose: every write here changes a single row — one order's
 * status, one transaction, or a soft delete — with no transaction boundary,
 * ledger entry, mail or notification to coordinate. The richer five-status
 * payment setter that does coordinate all of that already lives in
 * OrderFulfilmentService and is deliberately not merged with this one: it
 * voids instead of deleting, aligns paid_at with the mapped status and stamps
 * manual transactions with the cash method and NGN, none of which this
 * four-status contract does.
 *
 * Provenance kept with the code it explains:
 *  - WS-17 re-registers GET orders against Pos\OrderSourceController (Laravel
 *    keys routes by method+URI), so index() below is the base contract the
 *    other consumers and tests read; it must keep answering every input it
 *    used to answer, filters unvalidated as before;
 *  - the tenant guard stays in the controller body ahead of each write, not in
 *    middleware or FormRequest::authorize(); extracting the payload rules
 *    means an unauthorised AND malformed request now fails validation first
 *    (422) — accepted codebase-wide, with 403 preserved for valid payloads
 *    and route-binding 404 still preceding both.
 */
class OrderController extends ApiController
{
    use ResolvesManagementContext;

    private const ACCESS_DENIED = 'You do not have access to this order.';

    public function __construct(
        private readonly OrderRepository $repository,
        private readonly TenantGuard $tenantGuard,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Presence is decided here, with the same filled() semantics as
        // before: a blank status or search stays unfiltered, while a filled
        // but non-numeric store_id still filters on the 0 integer() yields.
        $filters = [
            'store_id' => $request->filled('store_id') ? $request->integer('store_id') : null,
            'status' => $request->filled('status') ? (string) $request->string('status') : null,
            'from' => $request->filled('from') ? $request->date('from') : null,
            'to' => $request->filled('to') ? $request->date('to') : null,
            'q' => $request->filled('q') ? (string) $request->string('q') : null,
        ];

        $orders = $this->repository->listQuery($this->user($request), $filters)
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            OrderResource::collection($orders->getCollection())->resolve($request),
            null,
            200,
            $this->paginationMeta($orders)
        );
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        return $this->ok([
            'order' => (new OrderDetailResource($this->repository->loadDetail($order)))->resolve($request),
        ]);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $data = $request->validated();

        $order->update(['status' => $data['status']]);

        return $this->ok(
            ['order' => (new OrderResource($order->fresh()))->resolve($request)],
            'Order status updated.'
        );
    }

    public function updatePaymentStatus(UpdateOrderPaymentStatusRequest $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $data = $request->validated();

        $map = [
            'pending' => TransactionStatus::PENDING->value,
            'paid' => TransactionStatus::CONFIRMED->value,
            'refunded' => TransactionStatus::REFUNDED->value,
            'failed' => TransactionStatus::CANCELED->value,
        ];

        $status = $map[$data['payment_status']];

        $transaction = $order->transactions()->latest()->first();

        if ($transaction) {
            $transaction->update(['status' => $status]);
        } else {
            Transaction::create([
                'reference' => 'MAN-'.strtoupper(Str::random(10)),
                'order_id' => $order->id,
                'business_id' => $order->business_id,
                'amount' => $order->total,
                'status' => $status,
                'paid_at' => $status === TransactionStatus::CONFIRMED->value ? now() : null,
            ]);
        }

        return $this->ok(
            ['order' => (new OrderResource($order->fresh()))->resolve($request)],
            'Payment status updated.'
        );
    }

    public function destroy(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOrder($request, $order);

        $order->delete();

        return $this->ok([], 'Order deleted.');
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        $this->tenantGuard->authorizeBusinessAndStore(
            $order,
            $this->user($request),
            (int) $order->store_id,
            self::ACCESS_DENIED,
        );
    }
}
