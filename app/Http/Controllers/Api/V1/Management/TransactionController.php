<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\RefundTransactionRequest;
use App\Http\Requests\Management\Transaction\RejectTransactionRequest;
use App\Http\Resources\Management\Transaction\TransactionDetailResource;
use App\Http\Resources\Management\Transaction\TransactionResource;
use App\Models\Transaction;
use App\Repositories\Management\Transaction\TransactionRepository;
use App\Services\Access\TenantGuard;
use App\Services\Management\Transaction\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The base management transactions API — list, show, confirm, reject, refund.
 *
 * Layering: the HTTP shape (status codes, message strings, the envelope,
 * pagination meta) stays here; the list query lives in
 * App\Repositories\Management\Transaction\TransactionRepository, the
 * confirm/refund money movement and its transaction boundaries in
 * App\Services\Management\Transaction\TransactionService, and the list/detail
 * payload shapes in App\Http\Resources\Management\Transaction\*. Reject is a
 * single-row status + metadata write with no transaction, ledger or
 * notification, so it stays inline like the other base slices' single-row
 * writes. No service was invented for it.
 *
 * Every URI this controller declares is re-registered later by the WS-18
 * module (routes/api/v1/management/ws18-transactions.php), which serves the
 * richer parity contract through TransactionParityController; this slice's
 * methods remain the in-repo fallback and keep their own contract — the layer
 * docblocks spell out where the two disagree — rather than being converged
 * into that work.
 *
 * Provenance kept with the code it explains:
 *  - the transaction guard stays in the controller body so its 403 keeps its
 *    place in the refusal order (route-binding 404 first, status 409s after),
 *    rather than moving into FormRequest::authorize();
 *  - the index deliberately has no FormRequest: this slice never validated its
 *    list filters, and rules would turn inputs it used to answer (per_page=500
 *    reaching the paginator) into 422s.
 */
class TransactionController extends ApiController
{
    use ResolvesManagementContext;

    private const ACCESS_DENIED = 'You do not have access to this transaction.';

    public function __construct(
        private readonly TransactionRepository $repository,
        private readonly TransactionService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // The same filled()/integer()/string() reading the inline query used,
        // so a non-numeric per_page or a blank q keeps the meaning it had.
        $filters = [
            'status' => $request->filled('status') ? (string) $request->string('status') : null,
            'q' => $request->filled('q') ? (string) $request->string('q') : null,
            'per_page' => $request->integer('per_page', 20),
        ];

        $transactions = $this->repository->paginateForUser($this->user($request), $filters);

        return $this->ok(
            $transactions->getCollection()
                ->map(fn (Transaction $transaction) => (new TransactionResource($transaction))->resolve($request))
                ->values()
                ->all(),
            null,
            200,
            $this->paginationMeta($transactions)
        );
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        $transaction->load(['order.items', 'order.customer', 'invoice.items', 'paymentMethod', 'storeBank']);

        return $this->ok(['transaction' => (new TransactionDetailResource($transaction))->resolve($request)]);
    }

    public function confirm(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        if ($transaction->status !== TransactionStatus::PENDING) {
            return $this->error('Only pending transactions can be confirmed.', 409);
        }

        $transaction = $this->service->confirm($transaction, $this->user($request)->id);

        return $this->ok(
            ['transaction' => (new TransactionResource($transaction->fresh()))->resolve($request)],
            'Payment confirmed.'
        );
    }

    public function reject(RejectTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        if ($transaction->status !== TransactionStatus::PENDING) {
            return $this->error('Only pending transactions can be rejected.', 409);
        }

        // Single-row status + metadata write — no transaction, ledger or
        // notification — so it stays here rather than gaining a service method.
        $transaction->update([
            'status' => TransactionStatus::CANCELED->value,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'rejection_reason' => $request->validated('reason'),
                'rejected_at' => now()->toDateTimeString(),
                'rejected_by' => $this->user($request)->id,
            ]),
        ]);

        return $this->ok(
            ['transaction' => (new TransactionResource($transaction->fresh()))->resolve($request)],
            'Payment rejected.'
        );
    }

    public function refund(RefundTransactionRequest $request, Transaction $transaction): JsonResponse
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

        try {
            $this->service->refund($transaction, $order, $request->validated('reason'), $this->user($request)->id);
        } catch (\Throwable $e) {
            // The base slice surfaces the raw failure message at 409; the WS-18
            // slice rewrites it into a friendly 422 — deliberately not merged.
            return $this->error($e->getMessage(), 409);
        }

        return $this->ok(
            ['transaction' => (new TransactionResource($transaction->fresh()))->resolve($request)],
            'Refund processed.'
        );
    }

    /**
     * The business-only shape TenantGuard already encodes, with the same 403
     * message the private method it replaces carried.
     *
     * Business-only, as this slice always was: it does not scope by the
     * caller's stores — the WS-18 slice adds that check for its own contract.
     */
    private function authorizeTransaction(Request $request, Transaction $transaction): void
    {
        app(TenantGuard::class)->authorizeBusiness(
            $transaction,
            $this->user($request),
            self::ACCESS_DENIED,
        );
    }
}
