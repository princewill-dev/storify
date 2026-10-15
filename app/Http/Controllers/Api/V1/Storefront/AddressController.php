<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Storefront\AddressStoreRequest;
use App\Http\Requests\Storefront\AddressUpdateRequest;
use App\Http\Resources\Storefront\AccountAddressResource;
use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Repositories\Storefront\AddressRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The customer's own address book — `storefront/account/addresses`.
 *
 * Layering: the HTTP shape (statuses, messages, envelope) and the two rules
 * that are not queries stay here; every scoped read and the multi-row default
 * bookkeeping live in AddressRepository; the payload rules in the two
 * Address*Requests; the response shape in AccountAddressResource.
 *
 * Writes that touch more than one row are transactional. "Setting an address
 * as default" is two statements — clear the old, set the new — and a failure
 * between them leaves a customer with no default at all, which is a state
 * checkout cannot preselect from.
 *
 * The id in the path is read as a string and resolved by the repository, which
 * scopes it to the customer and 404s anything else. That is deliberate: a
 * `{address}` belonging to another customer must be indistinguishable from one
 * that does not exist, which a route-model binding on the whole table would
 * not give us.
 */
class AddressController extends ApiController
{
    public function __construct(
        private readonly AddressRepository $repository,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        return $this->ok([
            'addresses' => AccountAddressResource::collection(
                $this->repository->saved($customer)
            )->resolve($request),
        ]);
    }

    public function store(AddressStoreRequest $request): JsonResponse
    {
        $customer = $this->customer($request);
        $data = $request->validated();

        // The first address a customer saves becomes their default whether or
        // not they said so. A one-entry book whose entry is not the default is
        // a checkout with nothing to preselect, which is the whole point of
        // saving it.
        $isDefault = (bool) ($data['is_default'] ?? false) || ! $this->repository->hasSaved($customer);

        $address = DB::transaction(function () use ($customer, $data, $isDefault) {
            if ($isDefault) {
                $this->repository->clearDefault($customer);
            }

            /** @var DeliveryAddress $address */
            $address = DeliveryAddress::create([
                ...$this->attributes($data),
                'customer_id' => $customer->id,
                'is_default' => $isDefault,
                'is_snapshot' => false,
            ]);

            return $address;
        });

        return $this->ok(
            ['address' => (new AccountAddressResource($address))->resolve($request)],
            'Address saved.',
            201
        );
    }

    public function update(AddressUpdateRequest $request, string $address): JsonResponse
    {
        $customer = $this->customer($request);
        $data = $request->validated();

        $saved = $this->repository->findSaved($customer, $address);

        DB::transaction(function () use ($customer, $saved, $data) {
            $saved->update($this->attributes($data));

            // Only an explicit `is_default` moves the flag. Leaving it out
            // preserves whatever the row already was — including staying the
            // default — so a client that posts the address form without the
            // toggle cannot silently unset it.
            if (! array_key_exists('is_default', $data)) {
                return;
            }

            if ($data['is_default']) {
                $this->repository->clearDefault($customer, $saved->id);
                $saved->update(['is_default' => true]);

                return;
            }

            $saved->update(['is_default' => false]);
            $this->repository->promoteDefault($customer);
        });

        return $this->ok(
            ['address' => (new AccountAddressResource($saved->fresh()))->resolve($request)],
            'Address updated.'
        );
    }

    public function setDefault(Request $request, string $address): JsonResponse
    {
        $customer = $this->customer($request);

        $saved = $this->repository->findSaved($customer, $address);

        DB::transaction(function () use ($customer, $saved) {
            $this->repository->clearDefault($customer, $saved->id);
            $saved->update(['is_default' => true]);
        });

        return $this->ok(
            ['address' => (new AccountAddressResource($saved->fresh()))->resolve($request)],
            'Default address updated.'
        );
    }

    public function destroy(Request $request, string $address): JsonResponse
    {
        $customer = $this->customer($request);

        $saved = $this->repository->findSaved($customer, $address);
        $wasDefault = $saved->is_default;

        DB::transaction(function () use ($customer, $saved, $wasDefault) {
            $saved->delete();

            // Deleting the default promotes the next newest, so the book never
            // ends up non-empty and unselected.
            if ($wasDefault) {
                $this->repository->promoteDefault($customer);
            }
        });

        return $this->ok([], 'Address removed.');
    }

    /**
     * The column set both write paths share.
     *
     * `is_default`, `customer_id`, `is_snapshot` and `delivery_route_id` are
     * not here: the first three are decided by the controller, and the route is
     * resolved per order at checkout rather than pinned to a saved address —
     * a customer who moves house keeps the same address row.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'label' => $data['label'] ?? null,
            'recipient_name' => $data['recipient_name'],
            'recipient_phone' => $data['recipient_phone'],
            'company_name' => $data['company_name'] ?? null,
            'street_address' => $data['street_address'],
            'apartment' => $data['apartment'] ?? null,
            'city' => $data['city'],
            'state' => $data['state'],
            'country' => $data['country'] ?? 'Nigeria',
            'landmark' => $data['landmark'] ?? null,
            'zip_code' => $data['zip_code'] ?? null,
            'map_link' => $data['map_link'] ?? null,
        ];
    }

    private function customer(Request $request): Customer
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return $customer;
    }
}
