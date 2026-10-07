<?php

namespace App\Http\Resources\Management;

use App\Models\Customer;
use Illuminate\Http\Request;

/**
 * WS-19 — the customer block on the deep-linkable detail screen, and the
 * payload every edit/suspend/activate response returns. Keys are appended to
 * the summary row in the order the controller's inline `detail()` emitted
 * them.
 */
final class CustomerParityDetailResource extends CustomerParityResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Customer $customer */
        $customer = $this->resource;

        $data = parent::toArray($request);

        $data['last_login'] = $customer->last_login?->toISOString();

        // Read from the customers-table columns, per the audit's correction:
        // the legacy controllers loaded a deliveryAddresses relation nothing
        // rendered. `apartment`/`zip_code` were dropped from this table by the
        // 2025_11_06 restructure and have not come back, so they are not
        // emitted.
        $data['address'] = [
            'street_address' => $customer->street_address,
            'city' => $customer->city,
            'state' => $customer->state,
            'country' => $customer->country,
        ];

        return $data;
    }
}
