<?php

namespace App\Http\Resources\Storefront;

use App\Models\DeliveryAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One address in the customer's own address book.
 *
 * Every writable column is echoed, including the ones the storefront does not
 * show in its summary line, so the edit form can be populated from a list
 * response without a second request.
 *
 * `full_address` is the model's own flattened accessor, sent as the string the
 * checkout recap and the book list both display. `is_snapshot` is deliberately
 * absent: the book never contains one, and exposing the flag would invite a
 * client to reason about a distinction that is not its to make.
 */
final class AccountAddressResource extends JsonResource
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
            'delivery_route_id' => $address->delivery_route_id,
            'full_address' => $address->full_address,
        ];
    }
}
