<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\RefundTransactionRequest;
use App\Http\Requests\Management\RejectTransactionRequest;
use App\Http\Requests\Management\TransactionIndexRequest;
use App\Http\Resources\Management\StoreOptionResource;
use App\Http\Resources\Management\TransactionDetailResource;
use App\Http\Resources\Management\TransactionResource;
use App\Http\Resources\Management\TransactionStatusResource;
use App\Models\Transaction;
use App\Repositories\Management\TransactionRepository;
use App\Services\Access\TenantGuard;
use App\Services\Transactions\TransactionWorkflowService;
use App\Support\Csv\CsvExporter;
use App\Support\Money\Naira;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * WS-18 — Transactions parity & payment emails.
 *
 * Registered by routes/api/v1/management/ws18-transactions.php, which reuses
 * the base method+URI pairs so this controller replaces the thin
 * TransactionController registrations (Laravel keys routes by method+URI).
 *
 * On top of the money movement the base controller already got right, this
 * carries the legacy list filters (store + date range), the store/customer
 * columns, the full detail read model (order context, customer block, balance
 * impact, payment slip, structured rejection/refund reasons), the three
 * queued payment mails, and store scoping for assigned staff.
 *
 * The read model lives in TransactionResource/TransactionDetailResource, the
 * scoping and filters in TransactionRepository and the money movement plus
 * its notifications in TransactionWorkflowService; this class keeps the HTTP
 * contract (status codes, messages, envelope, pagination) and the guards.
 */
class TransactionParityController extends ApiController
{
    use ResolvesManagementContext;

    private const ACCESS_DENIED = 'You do not have access to this transaction.';

    public function __construct(
        private readonly TransactionRepository $repository,
        private readonly TransactionWorkflowService $workflow,
        private readonly TenantGuard $tenantGuard,
    ) {}

    public function index(TransactionIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $user = $this->user($request);

        $transactions = $this->repository->listQuery($user, $filters)
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            [
                'transactions' => TransactionResource::collection($transactions->getCollection())->resolve($request),
                'stores' => StoreOptionResource::collection($this->repository->storeFilterOptions($user))->resolve($request),
                'statuses' => TransactionStatusResource::collection(TransactionStatus::cases())->resolve($request),
            ],
            null,
            200,
            $this->paginationMeta($transactions),
        );
    }

    /**
     * Pending count consumed by the sidebar badge (WS-34 mounts it).
     */
    public function pendingCount(Request $request): JsonResponse
    {
        // Legacy's badge joined orders, so an invoice-linked pending payment
        // never showed up. Every pending payment still needs a human decision,
        // so both count here.
        return $this->ok(['count' => $this->repository->pendingCount($this->user($request))]);
    }

    /**
     * Filtered CSV of the same rows the list renders — resolves the seeded
     * `transactions export` permission, which legacy granted but never routed.
     */
    public function export(TransactionIndexRequest $request): StreamedResponse
    {
        $query = $this->repository->listQuery($this->user($request), $request->validated());

        $filename = 'transactions-'.now()->format('Y-m-d-His').'.csv';

        return CsvExporter::stream($filename, TransactionResource::CSV_HEADERS, function ($handle) use ($query) {
            $query->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $transaction) {
                    CsvExporter::writeRow($handle, (new TransactionResource($transaction))->toCsvRow());
                }
            });
        });
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        return $this->ok(['transaction' => $this->detail($request, $transaction)]);
    }

    public function confirm(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        if ($transaction->status !== TransactionStatus::PENDING) {
            return $this->error('Only pending transactions can be confirmed.', 409);
        }

        $transaction = $this->workflow->confirm($transaction, $this->user($request)->id);

        return $this->ok(
            ['transaction' => $this->detail($request, $transaction)],
            'Payment confirmed. Store balance has been credited and customer has been notified.',
        );
    }

    public function reject(RejectTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        if ($transaction->status !== TransactionStatus::PENDING) {
            return $this->error('Only pending transactions can be rejected.', 409);
        }

        $transaction = $this->workflow->reject(
            $transaction,
            $request->validated('reason'),
            $this->user($request)->id,
        );

        return $this->ok(
            ['transaction' => $this->detail($request, $transaction)],
            // Invoice payments short-circuit the mail block; their screen
            // message reflects that.
            $transaction->invoice ? 'Invoice payment rejected.' : 'Payment rejected and customer has been notified.',
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

        $store = $this->repository->storeFor($transaction);

        if (! $store) {
            return $this->error('Transaction has no associated store.', 422);
        }

        $outcome = $this->workflow->refund(
            $transaction,
            $order,
            $store,
            $request->validated('reason'),
            $this->user($request)->id,
        );

        if ($outcome->hasFailed()) {
            if ($outcome->insufficientBalanceKobo !== null) {
                return $this->error(
                    'Insufficient store balance to process refund. Current balance: ₦'
                        .number_format(Naira::floatFromKobo($outcome->insufficientBalanceKobo), 2),
                    422,
                );
            }

            return $this->error('Failed to process refund: '.$outcome->failure, 422);
        }

        /** @var Transaction $refunded */
        $refunded = $outcome->transaction;

        return $this->ok(
            ['transaction' => $this->detail($request, $refunded)],
            'Refund processed. Store balance has been debited and customer has been notified.',
        );
    }

    /**
     * Detail read model for one transaction, eager-loaded and shaped.
     *
     * @return array<string, mixed>
     */
    private function detail(Request $request, Transaction $transaction): array
    {
        return (new TransactionDetailResource($this->repository->loadDetail($transaction)))->resolve($request);
    }

    /**
     * @throws HttpException
     */
    private function authorizeTransaction(Request $request, Transaction $transaction): void
    {
        $user = $this->user($request);

        $this->tenantGuard->authorizeBusiness($transaction, $user, self::ACCESS_DENIED);

        if (! $user->isStaff()) {
            return;
        }

        $storeId = $transaction->order?->store_id ?? $transaction->invoice?->store_id;

        if ($storeId === null || ! $this->repository->staffStoreIds($user)->contains((int) $storeId)) {
            abort(403, self::ACCESS_DENIED);
        }
    }
}
