<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\UpdateProfileRequest;
use App\Http\Resources\Storefront\AccountDownloadResource;
use App\Http\Resources\Storefront\AccountOrderDetailResource;
use App\Http\Resources\Storefront\AccountOrderDownloadResource;
use App\Http\Resources\Storefront\AccountOrderResource;
use App\Http\Resources\Storefront\AccountProfileResource;
use App\Models\Customer;
use App\Repositories\Storefront\AccountRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer account API — order history, one order's detail, the digital
 * downloads list and the profile edit.
 *
 * Layering: the HTTP shape (status codes, the envelope, the message strings,
 * pagination meta) stays here; every customer-scoped read lives in
 * AccountRepository, whose where('customer_id') is the account API's tenancy
 * boundary — losing it would leak another account's orders or download tokens;
 * the profile payload rules live in UpdateProfileRequest; the payload shapes in
 * the Storefront resources, moved verbatim because the storefront SPA and the
 * exact-JSON tests read field names, types and order as-is.
 *
 * No service on purpose: the only write is a single-row profile update with no
 * transaction boundary, ledger, mail or notification to coordinate, and the
 * reads compose no workflow beyond the repository calls.
 *
 * Provenance kept with the code it explains:
 *  - the fully-paid gate on an order's downloads stays in showOrder(), at the
 *    response's composition point — downloads are an entitlement, not a query
 *    concern;
 *  - the order list's status filter keeps the filled() semantics it had (a
 *    blank status stays unfiltered) and per_page keeps its integer() default;
 *  - this endpoint has no in-body guard to reorder: authorization is the
 *    route's sanctum_customer middleware and the customer audience token
 *    check, so extracting the rules to the FormRequest moves nothing else.
 */
class AccountController extends ApiController
{
    public function __construct(
        private readonly AccountRepository $repository,
    ) {}

    public function orders(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        // Presence is decided here with the same filled() semantics as the
        // inline when(): a blank status stays unfiltered.
        $status = $request->filled('status') ? (string) $request->string('status') : null;

        $orders = $this->repository->ordersQuery($customer, $status)
            ->paginate((int) $request->integer('per_page', 15));

        return $this->ok(
            AccountOrderResource::collection($orders->getCollection())->resolve($request),
            null,
            200,
            $this->paginationMeta($orders)
        );
    }

    public function showOrder(Request $request, string $orderNumber): JsonResponse
    {
        $customer = $this->customer($request);

        $order = $this->repository->findOrderForCustomer($customer, $orderNumber);

        // Downloads are an entitlement: only a fully paid order exposes them.
        // values() keeps the payload a JSON array, as the inline
        // ->values()->all() did after filter() had preserved the original keys.
        $downloads = $order->isFullyPaid()
            ? $this->repository->downloadsFor($order)->values()
            : collect();

        return $this->ok([
            'order' => (new AccountOrderDetailResource($order))->resolve($request),
            'downloads' => AccountOrderDownloadResource::collection($downloads)->resolve($request),
        ]);
    }

    public function downloads(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        $downloads = $this->repository->downloadsQuery($customer)
            ->paginate((int) $request->integer('per_page', 15));

        return $this->ok(
            AccountDownloadResource::collection($downloads->getCollection())->resolve($request),
            null,
            200,
            $this->paginationMeta($downloads)
        );
    }

    public function updateProfile(UpdateProfileRequest $request): JsonResponse
    {
        $customer = $this->customer($request);

        $customer->update($request->validated());

        return $this->ok(
            ['user' => (new AccountProfileResource($customer))->resolve($request)],
            'Profile updated.'
        );
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return $customer;
    }
}
