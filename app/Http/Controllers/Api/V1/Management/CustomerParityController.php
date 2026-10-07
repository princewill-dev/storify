<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\CustomerParityIndexRequest;
use App\Http\Requests\Management\CustomerParitySuspendRequest;
use App\Http\Requests\Management\CustomerParityUpdateRequest;
use App\Http\Resources\Management\CustomerParityActivityResource;
use App\Http\Resources\Management\CustomerParityDetailResource;
use App\Http\Resources\Management\CustomerParityOrderResource;
use App\Http\Resources\Management\CustomerParityResource;
use App\Http\Resources\Management\CustomerParityStatsResource;
use App\Http\Resources\Management\CustomerParityTransactionResource;
use App\Models\Customer;
use App\Repositories\Management\CustomerParityRepository;
use App\Services\Access\TenantGuard;
use App\Services\Management\CustomerLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * WS-19 — customers module parity.
 *
 * Lives beside CustomerController (which it replaces on the shared routes)
 * because the base controller's list/show/update shape is consumed by other
 * workstreams and must not change. This one carries what the legacy screens
 * rendered and the base payloads dropped: country/store filters and the four
 * stat cards, account_id and full-name search, the deep-linkable detail screen
 * (pending count, address columns, transactions, activity timeline), status
 * editing with email-verification sync, and suspend/activate with a required,
 * persisted reason.
 *
 * Legacy fixes carried here deliberately:
 * - the actor is the real `user_id` (legacy stashed it in `metadata.user_id`);
 * - restricted staff are scoped to their assigned stores instead of legacy's
 *   per-current-user order counts, which made an owner and their cashier see
 *   different numbers for the same customer;
 * - `total_spent` is completed orders and says so (`spend_basis`), where three
 *   legacy definitions existed;
 * - the address block reads the customers-table columns, not the dead
 *   `deliveryAddresses` relation the legacy controllers loaded and never
 *   rendered.
 *
 * Business-vs-admin suspension e-mail decision: a business suspend/activate
 * writes the audit trail but does NOT e-mail the customer. The only
 * customer-facing account mailable is framed as platform administration
 * ("suspended by our administration team"), so reusing it for a
 * business-initiated action would misattribute the suspension to Storify. The
 * admin module (deferred) keeps the e-mails; a business-framed mail needs its
 * own template, which is net-new rather than parity.
 *
 * The read model lives in the CustomerParity resources, the scoping, filters
 * and aggregates in CustomerParityRepository, and the edit/suspend/activate
 * workflows with their transactions and audit rows in
 * CustomerLifecycleService; this class keeps the HTTP contract (status codes,
 * messages, envelope, pagination) and the tenant guards.
 */
class CustomerParityController extends ApiController
{
    use ResolvesManagementContext;

    private const CUSTOMER_ACCESS_DENIED = 'You do not have access to this customer.';

    private const STORE_ACCESS_DENIED = 'You do not have access to this store.';

    public function __construct(
        private readonly CustomerParityRepository $repository,
        private readonly CustomerLifecycleService $lifecycle,
        private readonly TenantGuard $tenantGuard,
    ) {}

    public function index(CustomerParityIndexRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $user = $this->user($request);
        $storeIds = $this->accessibleStoreIds($request);

        if (isset($filters['store_id'])) {
            $this->tenantGuard->authorizeStoreId($user, (int) $filters['store_id'], self::STORE_ACCESS_DENIED);
        }

        $customers = $this->repository->listQuery($user, $storeIds, $filters)
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            [
                'customers' => CustomerParityResource::collection($customers->getCollection())->resolve($request),
                'stats' => $this->repository->stats($user, $storeIds),
            ],
            null,
            200,
            $this->paginationMeta($customers),
        );
    }

    public function countries(Request $request): JsonResponse
    {
        $countries = $this->repository->countryOptions(
            $this->user($request),
            $this->accessibleStoreIds($request),
        );

        return $this->ok(['countries' => $countries->all()]);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $blocks = $this->repository->detailBlocks($customer, $this->user($request));

        // The edit modal's account summary reads `orders_count` off this
        // payload; without it the row renders as 0 for the very customer the
        // list counted correctly. Reuse the aggregate above so the count keeps
        // the same scoping as `stats.total_orders` and the list's withCount.
        $customer->setAttribute('orders_count', (int) ($blocks['aggregate']->total_orders ?? 0));

        return $this->ok([
            'customer' => (new CustomerParityDetailResource($customer))->resolve($request),
            'stats' => (new CustomerParityStatsResource($blocks['aggregate']))->resolve($request),
            'recent_orders' => CustomerParityOrderResource::collection($blocks['recent_orders'])->resolve($request),
            'transactions' => CustomerParityTransactionResource::collection($blocks['transactions'])->resolve($request),
            'activity' => CustomerParityActivityResource::collection($blocks['activity'])->resolve($request),
        ]);
    }

    public function update(CustomerParityUpdateRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        // The request normalised the status to lowercase; the schema stores the
        // uppercase spelling.
        $data = $request->validated();

        if (isset($data['status'])) {
            $data['status'] = strtoupper($data['status']);
        }

        $this->lifecycle->update($customer, $data, $request);

        return $this->ok(
            ['customer' => (new CustomerParityDetailResource($customer->fresh()))->resolve($request)],
            'Customer updated.',
        );
    }

    public function suspend(CustomerParitySuspendRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        if ($customer->status === Customer::STATUS_SUSPENDED) {
            return $this->error('This customer is already suspended.', 422);
        }

        $this->lifecycle->suspend($customer, $request->validated('reason'), $request);

        return $this->ok(
            ['customer' => (new CustomerParityDetailResource($customer->fresh()))->resolve($request)],
            'Customer suspended.',
        );
    }

    public function activate(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        if ($customer->status === Customer::STATUS_ACTIVE) {
            return $this->error('This customer is already active.', 422);
        }

        $this->lifecycle->activate($customer, $request);

        return $this->ok(
            ['customer' => (new CustomerParityDetailResource($customer->fresh()))->resolve($request)],
            'Customer activated.',
        );
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
