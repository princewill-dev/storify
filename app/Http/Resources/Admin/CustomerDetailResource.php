<?php

namespace App\Http\Resources\Admin;

use App\Models\Customer;
use App\Models\DeliveryAddress;
use Illuminate\Http\Request;

/**
 * WS-9 (admin console) — the customer detail card.
 *
 * The directory row plus the two address blocks the legacy detail page
 * rendered: the customer-table address columns and the default delivery
 * address (what the storefront actually ships to) as a nullable extra. The
 * address row arrives from CustomerRepository::defaultDeliveryAddress() so
 * response shaping issues no queries of its own.
 */
class CustomerDetailResource extends CustomerResource
{
    public function __construct(
        Customer $customer,
        private readonly ?DeliveryAddress $defaultDeliveryAddress = null,
        private readonly ?int $ordersCount = null,
    ) {
        parent::__construct($customer);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Customer $customer */
        $customer = $this->resource;

        $payload = parent::toArray($request);

        // The list's withCount alias has no counterpart on the bound model, so
        // the console passes the same figure the tiles use. The assignment
        // overwrites in place, so the key keeps its position in the card.
        if ($this->ordersCount !== null) {
            $payload['orders_count'] = $this->ordersCount;
        }

        $deliveryAddress = $this->defaultDeliveryAddress;

        return $payload + [
            'address' => [
                'street_address' => $customer->street_address,
                'city' => $customer->city,
                'state' => $customer->state,
                'country' => $customer->country,
            ],
            'default_delivery_address' => $deliveryAddress ? [
                'id' => $deliveryAddress->id,
                'recipient_name' => $deliveryAddress->recipient_name,
                'recipient_phone' => $deliveryAddress->recipient_phone,
                'street_address' => $deliveryAddress->street_address,
                'apartment' => $deliveryAddress->apartment,
                'city' => $deliveryAddress->city,
                'state' => $deliveryAddress->state,
                'country' => $deliveryAddress->country,
                'zip_code' => $deliveryAddress->zip_code,
                'is_default' => (bool) $deliveryAddress->is_default,
                'full_address' => $deliveryAddress->full_address,
            ] : null,
        ];
    }
}
