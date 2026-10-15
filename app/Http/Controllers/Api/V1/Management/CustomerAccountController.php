<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\CustomerAccountIndexRequest;
use App\Http\Requests\Management\CustomerErasureDecisionRequest;
use App\Http\Requests\Management\CustomerMessageRequest;
use App\Http\Requests\Management\CustomerNoteRequest;
use App\Http\Resources\Management\CustomerAccountAddressResource;
use App\Http\Resources\Management\CustomerConsentResource;
use App\Http\Resources\Management\CustomerExportResource;
use App\Http\Resources\Management\CustomerMessageResource;
use App\Http\Resources\Management\CustomerNoteResource;
use App\Http\Resources\Management\CustomerParityOrderResource;
use App\Http\Resources\Management\CustomerParityResource;
use App\Http\Resources\Management\CustomerParityTransactionResource;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Repositories\Management\CustomerAccountRepository;
use App\Services\Access\TenantGuard;
use App\Services\Management\CustomerErasureService;
use App\Services\Management\CustomerMessageService;
use App\Services\Management\CustomerNotesService;
use App\Support\Csv\CsvExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * WS-56 — the Customers section's tabbed reads, and the four writes that hang
 * off them.
 *
 * CustomerParityController stays the owner of the list, the stat cards and the
 * detail summary, which WS-19 shipped and the management SPA already consumes.
 * This one owns what is opened on demand: a customer's orders, payments,
 * addresses, notes, messages and consent trail, plus the deletion queue and the
 * compose-and-send paths. Splitting them keeps the parity controller's HTTP
 * contract frozen while this one is free to add.
 *
 * The tenant boundary is the same one, taken from the same place: a customer
 * reached by `{customer}` binds on `account_id` across the whole table, so every
 * method here calls authorizeCustomer() before it reads a row, and restricted
 * staff additionally see only orders from their assigned stores — enforced in
 * the repository through ScopesCustomerReads rather than re-derived per tab.
 *
 * The writes are all services, not repository calls: each commits more than one
 * row and has to be atomic. This class owns the HTTP contract only — status
 * codes, envelope, messages, pagination, and the guards.
 */
class CustomerAccountController extends ApiController
{
    use ResolvesManagementContext;

    private const CUSTOMER_ACCESS_DENIED = 'You do not have access to this customer.';

    private const STORE_ACCESS_DENIED = 'You do not have access to this store.';

    public function __construct(
        private readonly CustomerAccountRepository $repository,
        private readonly CustomerNotesService $notes,
        private readonly CustomerMessageService $messages,
        private readonly CustomerErasureService $erasures,
        private readonly TenantGuard $tenantGuard,
    ) {}

    public function orders(CustomerAccountIndexRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $orders = $this->repository
            ->ordersQuery($customer, $this->user($request))
            ->paginate($this->perPage($request))
            ->withQueryString();

        return $this->ok(
            ['orders' => CustomerParityOrderResource::collection($orders->getCollection())->resolve($request)],
            null,
            200,
            $this->paginationMeta($orders),
        );
    }

    public function payments(CustomerAccountIndexRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $payments = $this->repository
            ->paymentsQuery($customer, $this->user($request))
            ->paginate($this->perPage($request))
            ->withQueryString();

        return $this->ok(
            ['payments' => CustomerParityTransactionResource::collection($payments->getCollection())->resolve($request)],
            null,
            200,
            $this->paginationMeta($payments),
        );
    }

    public function addresses(CustomerAccountIndexRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $addresses = $this->repository
            ->addressesQuery($customer)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return $this->ok(
            ['addresses' => CustomerAccountAddressResource::collection($addresses->getCollection())->resolve($request)],
            null,
            200,
            $this->paginationMeta($addresses),
        );
    }

    public function notes(CustomerAccountIndexRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $notes = $this->repository
            ->notesQuery($customer)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return $this->ok(
            ['notes' => CustomerNoteResource::collection($notes->getCollection())->resolve($request)],
            null,
            200,
            $this->paginationMeta($notes),
        );
    }

    public function storeNote(CustomerNoteRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $note = $this->notes->write(
            $customer,
            $this->user($request),
            (string) $request->validated('body'),
            $request,
        );

        return $this->ok(
            ['note' => (new CustomerNoteResource($note->load('user:id,name')))->resolve($request)],
            'Note added.',
            201,
        );
    }

    public function destroyNote(Request $request, Customer $customer, CustomerNote $note): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);
        $this->authorizeNote($customer, $note);

        $this->notes->remove($note, $this->user($request), $request);

        return $this->ok([], 'Note deleted.');
    }

    public function messages(CustomerAccountIndexRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $messages = $this->repository
            ->messagesQuery($customer)
            ->paginate($this->perPage($request))
            ->withQueryString();

        return $this->ok(
            ['messages' => CustomerMessageResource::collection($messages->getCollection())->resolve($request)],
            null,
            200,
            $this->paginationMeta($messages),
        );
    }

    public function consents(CustomerAccountIndexRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        // Not paginated: a consent trail is a handful of rows for a customer
        // who has ever made a choice, and a cursor through four rows is a worse
        // screen than four rows.
        $consents = $this->repository->consentsQuery($customer)->get();

        return $this->ok([
            'consents' => CustomerConsentResource::collection($consents)->resolve($request),
        ]);
    }

    /**
     * One message to one customer.
     *
     * Consent is deliberately not consulted: this is reached from the person's
     * own detail screen, and a message written about one customer is a reply to
     * something they did. The broadcast endpoint is where consent is the gate.
     */
    public function sendMessage(CustomerMessageRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $data = $request->validated();

        $message = $this->messages->send(
            $customer,
            $this->user($request),
            (string) $data['subject'],
            (string) $data['body'],
            (string) $data['category'],
            $request,
        );

        return $this->ok(
            ['message' => (new CustomerMessageResource($message->load('user:id,name')))->resolve($request)],
            'Message queued.',
            201,
        );
    }

    /**
     * One message to everyone the filters name — the campaign send.
     *
     * The audience and the send are both built from the same filter set the
     * customer list uses, so what is sent to is what was on screen when the
     * sender composed it. Consent, suspension and outstanding deletion requests
     * are applied by the repository's audienceQuery, not here, because they are
     * properties of the customers rather than of the message.
     */
    public function broadcast(CustomerMessageRequest $request): JsonResponse
    {
        // Both sets, from one payload: the message and the audience it targets.
        // CustomerMessageRequest extends the index request for exactly this, so
        // the filters the sender narrowed the list with arrive here intact.
        $data = $request->validated();

        if (isset($data['store_id'])) {
            $this->tenantGuard->authorizeStoreId(
                $this->user($request),
                (int) $data['store_id'],
                self::STORE_ACCESS_DENIED,
            );
        }

        $result = $this->messages->broadcast(
            $this->repository->audienceQuery(
                $this->user($request),
                $this->accessibleStoreIds($request),
                $data,
                (string) $data['category'],
            ),
            $this->user($request),
            (string) $data['subject'],
            (string) $data['body'],
            (string) $data['category'],
            $request,
        );

        return $this->ok(
            ['result' => $result],
            $this->broadcastMessage($result),
            // 201: rows were created, one per recipient. The same status the
            // one-to-one send returns, so the two agree.
            201,
        );
    }

    /**
     * Filtered CSV of the same rows the customer list renders.
     *
     * Streamed and chunked, so the export is flat in memory regardless of how
     * large the book is. It leaves the API envelope behind on purpose: a
     * download is a file, not a payload, and wrapping it would mean the browser
     * saved JSON.
     */
    public function export(CustomerAccountIndexRequest $request): StreamedResponse
    {
        $filters = $request->validated();

        if (isset($filters['store_id'])) {
            $this->tenantGuard->authorizeStoreId(
                $this->user($request),
                (int) $filters['store_id'],
                self::STORE_ACCESS_DENIED,
            );
        }

        $query = $this->repository->exportQuery(
            $this->user($request),
            $this->accessibleStoreIds($request),
            $filters,
        );

        $filename = 'customers-'.now()->format('Y-m-d-His').'.csv';

        return CsvExporter::stream($filename, CustomerExportResource::CSV_HEADERS, function ($handle) use ($query) {
            $query->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $customer) {
                    CsvExporter::writeRow($handle, (new CustomerExportResource($customer))->toCsvRow());
                }
            });
        });
    }

    /**
     * The deletion queue: who has asked to be erased and has not been answered.
     */
    public function deletionRequests(CustomerAccountIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();

        if (isset($filters['store_id'])) {
            $this->tenantGuard->authorizeStoreId(
                $this->user($request),
                (int) $filters['store_id'],
                self::STORE_ACCESS_DENIED,
            );
        }

        $requests = $this->repository
            ->deletionRequestsQuery($this->user($request), $this->accessibleStoreIds($request))
            ->paginate($this->perPage($request))
            ->withQueryString();

        return $this->ok(
            ['requests' => CustomerParityResource::collection($requests->getCollection())->resolve($request)],
            null,
            200,
            $this->paginationMeta($requests),
        );
    }

    /**
     * Approve a deletion request: anonymise the person, keep the money.
     *
     * Irreversible, and the service says why in its own words — the short
     * version is that `orders.customer_id` cascades, so deleting the row would
     * destroy the financial records behind a screen that promised to remove a
     * name. Guarded on the request actually standing: approving one that was
     * never made, or was already answered, is not a decision anyone is entitled
     * to make.
     */
    public function approveErasure(CustomerErasureDecisionRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        if ($customer->deletion_requested_at === null) {
            return $this->error('This customer has no deletion request outstanding.', 409);
        }

        if ($customer->status === Customer::STATUS_DELETED) {
            return $this->error('This customer has already been erased.', 409);
        }

        $this->erasures->approve($customer, $this->user($request), $request);

        return $this->ok(
            ['customer' => (new CustomerParityResource($customer->fresh()))->resolve($request)],
            'Customer erased. Orders and payments have been retained.',
        );
    }

    /**
     * Decline a deletion request and leave the account standing.
     *
     * The reason is required by the request: the customer is not told (nothing
     * sends from a business-framed address yet), so the audit row is the only
     * account of why.
     */
    public function rejectErasure(CustomerErasureDecisionRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        if ($customer->deletion_requested_at === null) {
            return $this->error('This customer has no deletion request outstanding.', 409);
        }

        $this->erasures->reject($customer, $this->user($request), (string) $request->validated('reason'), $request);

        return $this->ok(
            ['customer' => (new CustomerParityResource($customer->fresh()))->resolve($request)],
            'Deletion request declined. The account is unchanged.',
        );
    }

    private function perPage(CustomerAccountIndexRequest $request): int
    {
        return (int) ($request->validated('per_page') ?? 20);
    }

    /**
     * @param  array{queued: int, skipped: int, capped: int}  $result
     */
    private function broadcastMessage(array $result): string
    {
        $message = "Message queued for {$result['queued']} customer(s).";

        if ($result['capped'] > 0) {
            $message .= " {$result['capped']} were left out of this send — narrow the filters to reach them.";
        }

        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} could not be queued.";
        }

        return $message;
    }

    /**
     * A note is addressed by its own id and the customer is in the path, so a
     * note id that belongs to somebody else must not resolve. A 404, like any
     * other address that does not exist under this customer.
     */
    private function authorizeNote(Customer $customer, CustomerNote $note): void
    {
        if ((int) $note->customer_id !== (int) $customer->id) {
            abort(404, 'Note not found.');
        }
    }

    /**
     * @throws HttpException
     */
    private function authorizeCustomer(Request $request, Customer $customer): void
    {
        $user = $this->user($request);

        $this->tenantGuard->authorizeBusiness($customer, $user, self::CUSTOMER_ACCESS_DENIED);

        if ($user->isRestrictedStaff()
            && ! $customer->orders()->whereIn('store_id', $this->accessibleStoreIds($request))->exists()) {
            abort(403, self::CUSTOMER_ACCESS_DENIED);
        }
    }
}
