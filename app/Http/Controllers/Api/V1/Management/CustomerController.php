<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\SuspendCustomerRequest;
use App\Http\Requests\Management\UpdateCustomerRequest;
use App\Http\Resources\Management\CustomerRecentOrderResource;
use App\Http\Resources\Management\CustomerResource;
use App\Models\Customer;
use App\Repositories\Management\CustomerRepository;
use App\Services\Access\TenantGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The base customers API.
 *
 * Layer split: the list query and the show screen's aggregates live in
 * CustomerRepository; the row shapes in CustomerResource /
 * CustomerRecentOrderResource; the update and suspend payloads in their
 * FormRequests; the business scoping check in TenantGuard.
 *
 * No service on purpose: every write here changes one customers row, with no
 * transaction, ledger entry, mail or notification to coordinate.
 */
class CustomerController extends ApiController
{
    use ResolvesManagementContext;

    private const ACCESS_DENIED = 'You do not have access to this customer.';

    public function __construct(
        private readonly CustomerRepository $repository,
        private readonly TenantGuard $tenantGuard,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Presence is decided here, with the same filled() semantics as
        // before: a blank status or search stays unfiltered, "0" is a term.
        $filters = [
            'status' => $request->filled('status') ? (string) $request->string('status') : null,
            'q' => $request->filled('q') ? (string) $request->string('q') : null,
        ];

        $customers = $this->repository->listQuery($this->user($request), $filters)
            ->paginate((int) $request->integer('per_page', 20));

        return $this->ok(
            CustomerResource::collection($customers->getCollection())->resolve($request),
            null,
            200,
            $this->paginationMeta($customers)
        );
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        return $this->ok([
            'customer' => (new CustomerResource($customer))->resolve($request),
            'stats' => $this->repository->orderStats($customer),
            'recent_orders' => CustomerRecentOrderResource::collection(
                $customer->orders()->latest()->limit(10)->get()
            )->resolve($request),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $customer->update($request->validated());

        return $this->ok(['customer' => (new CustomerResource($customer->fresh()))->resolve($request)], 'Customer updated.');
    }

    public function suspend(SuspendCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $customer->update(['status' => Customer::STATUS_SUSPENDED, 'email_verified_at' => null]);

        return $this->ok(['customer' => (new CustomerResource($customer->fresh()))->resolve($request)], 'Customer suspended.');
    }

    public function activate(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeCustomer($request, $customer);

        $customer->update([
            'status' => Customer::STATUS_ACTIVE,
            'email_verified_at' => $customer->email_verified_at ?? now(),
        ]);

        return $this->ok(['customer' => (new CustomerResource($customer->fresh()))->resolve($request)], 'Customer activated.');
    }

    private function authorizeCustomer(Request $request, Customer $customer): void
    {
        $this->tenantGuard->authorizeBusiness($customer, $this->user($request), self::ACCESS_DENIED);
    }
}
