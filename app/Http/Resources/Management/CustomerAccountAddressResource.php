<?php

namespace App\Http\Resources\Management;

use App\Models\DeliveryAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-56 — an address on the customer's Addresses tab.
 *
 * The saved book only; the per-order snapshots are not listed here, because
 * each one is already shown against the order it was taken for and repeating
 * them would fill the tab with copies of a single address. A storefront-side
 * resource exists for the same rows with a narrower field set; this one adds
 * nothing a customer may not see, but is separate because the two are consumed
 * by different apps and a shared resource becomes a negotiation between them.
 */
final class CustomerAccountAddressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DeliveryAddress $address */
        $address = $this->resource;

        return [
            'id' => $address->id,
            'label' => $address->label,
            'recipient_name' => $address->recipient_name,
            'recipient_phone' => $address->recipient_phone,
            'company_name' => $address->company_name,
            'street_address' => $address->street_address,
            'apartment' => $address->apartment,
            'city' => $address->city,
            'state' => $address->state,
            'country' => $address->country,
            'landmark' => $address->landmark,
            'zip_code' => $address->zip_code,
            'map_link' => $address->map_link,
            'is_default' => (bool) $address->is_default,
            'created_at' => $address->created_at?->toISOString(),
        ];
    }
}
