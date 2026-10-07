<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\ListCustomersRequest;
use App\Http\Requests\Admin\SuspendCustomerRequest;
use App\Http\Requests\Admin\UpdateCustomerRequest;
use App\Http\Resources\Admin\CustomerConsoleResource;
use App\Http\Resources\Admin\CustomerDetailResource;
use App\Http\Resources\Admin\CustomerResource;
use App\Models\Customer;
use App\Repositories\Admin\CustomerRepository;
use App\Services\Admin\CustomerModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-9 (admin console) — platform customer console.
 *
 * The legacy `/office/customers` screens (directory, detail, edit, suspend,
 * activate) had no equivalent in the new stack at all: the only customer API
 * was the business-scoped management one, which cannot serve platform
 * oversight. This controller is the platform-wide console. It is deliberately
 * unaware of tenant scoping — a platform admin sees every business's
 * customers — and is guarded instead by `permission:admin.customers` plus the
 * platform-role check in the EnsuresPlatformAdmin trait.
 *
 * Legacy defects fixed rather than cloned:
 * - `sort_by`/`sort_order` were passed straight to `orderBy` (a SQL injection
 *   seam with arbitrary columns in the error path); the column and direction
 *   are whitelisted here.
 * - The country dropdown joined `delivery_addresses` to `delivery_routes` on
 *   every request; it is derived once and cached, and it also includes the
 *   address columns the legacy detail card actually rendered, so every option
 *   in the dropdown filters at least one row.
 * - The unused `this_month` counter is dropped, per the roadmap ("drop the
 *   unused stat or display it deliberately") — it never reached the view.
 * - Suspension/activation e-mails were sent inside the DB transaction, so a
 *   slow/newly-down mail transport held the row lock; they are queued after
 *   the commit and a mail failure never rolls the mutation back (legacy
 *   logged them non-fatally).
 * - The "already suspended"/"already active" guards refused with a warning
 *   flash and no status code; they are 422s here.
 * - Legacy `Customer::STATUS_*` is uppercase in the schema; payloads speak
 *   lowercase (matching the management API) and inputs are normalised, so an
 *   uppercase legacy-shaped client still works.
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin
 * FormRequests (`ListCustomersRequest`, `UpdateCustomerRequest`,
 * `SuspendCustomerRequest`), queries in `CustomerRepository`, the
 * multi-table workflows and their transaction boundaries in
 * `CustomerModerationService`, and response shaping in `CustomerResource` /
 * `CustomerDetailResource` / `CustomerConsoleResource`; the platform-admin
 * guard deliberately stays here so its order (403 before the refusals) is
 * unchanged.
 */
class CustomerController extends ApiController
{
    /**
     * The admin console is a platform surface. Audience + `admin.customers`
     * alone are not enough: every business's in-business "Super Admin" role is
     * seeded with the full permission bundle, which contains the admin.* names,
     * so a business-scoped account holding a leaked admin-audience token would
     * otherwise read and mutate every tenant's customers (the same hole WS-1
     * and WS-4 documented).
     */
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CustomerModerationService $moderation,
    ) {}

    /**
     * The directory. Stats are platform-wide and deliberately independent of
     * the active filters — they are the cards above the table, not the page
     * count (the pagination meta answers that).
     */
    public function index(ListCustomersRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $page = $this->customers->paginateForDirectory($request->validated());

        return $this->ok(
            ['customers' => $page->getCollection()->map(fn (Customer $customer) => $this->row($customer))->values()->all()],
            null,
            200,
            [...$this->paginationMeta($page), 'stats' => $this->customers->stats()],
        );
    }

    /**
     * The country filter's options, derived from the two sources the legacy
     * screen joined and served from the repository's ten-minute cache.
     */
    public function countries(): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->ok(['countries' => $this->customers->countryOptions()]);
    }

    /**
     * The customer console: the four stat tiles, the info and address cards,
     * the last ten orders and transactions, and the audit feed the legacy
     * detail page rendered.
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->customers->loadDetailRelations($customer);

        return $this->ok(
            CustomerConsoleResource::make($customer, $this->customers->detailBlocks($customer))->resolve($request),
        );
    }

    /**
     * Edit details and status from the list or the console. Legacy's status
     * side effect and the partial-payload semantics live in
     * CustomerModerationService.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $this->moderation->update($customer, $request->validated(), $request->user());

        return $this->ok(['customer' => $this->card($customer->fresh(), $request)], 'Customer updated.');
    }

    /**
     * Suspend with a required, persisted reason and the customer e-mail the
     * legacy screen sent. The already-suspended refusal stays here so its 422
     * precedes the workflow.
     */
    public function suspend(SuspendCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($customer->status === Customer::STATUS_SUSPENDED) {
            return $this->error('This customer is already suspended.', 422);
        }

        $this->moderation->suspend($customer, $request->validated()['reason'], $request->user());

        return $this->ok(['customer' => $this->card($customer->fresh(), $request)], 'Customer suspended.');
    }

    /**
     * Activate a suspended or deleted customer, verifying the e-mail if it is
     * not already, with legacy's notification.
     */
    public function activate(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizePlatformAdmin();

        if ($customer->status === Customer::STATUS_ACTIVE) {
            return $this->error('This customer is already active.', 422);
        }

        $this->moderation->activate($customer, $request->user());

        return $this->ok(['customer' => $this->card($customer->fresh(), $request)], 'Customer activated.');
    }

    /**
     * The directory row shaped by CustomerResource. Kept as a thin private
     * seam so the response sites read as they did before the extraction.
     *
     * @return array<string, mixed>
     */
    private function row(Customer $customer): array
    {
        return CustomerResource::make($customer)->resolve();
    }

    /**
     * The action responses' customer card — the console detail payload
     * without the stats/orders/transactions/activity blocks. Kept as a thin
     * private seam so the response sites read as they did before the
     * extraction.
     *
     * @return array<string, mixed>
     */
    private function card(Customer $customer, Request $request): array
    {
        $this->customers->loadDetailRelations($customer);

        return CustomerDetailResource::make($customer, $this->customers->defaultDeliveryAddress($customer))
            ->resolve($request);
    }
}
