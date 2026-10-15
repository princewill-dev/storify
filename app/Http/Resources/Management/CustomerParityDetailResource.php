<?php

namespace App\Http\Resources\Management;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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

        // `lifetime_value` and `last_order_at` are not columns — the controller
        // sets them from the same SQL aggregate the stats block is built from,
        // so the header and the tile can never disagree. `last_order_at` comes
        // back from MAX() as a raw string rather than a cast attribute, hence
        // the parse. Null when the customer has never ordered, which is the
        // honest answer and not a zero.
        $data['lifetime_value'] = isset($customer->lifetime_value)
            ? (string) $customer->lifetime_value
            : null;

        $data['last_order_at'] = isset($customer->last_order_at) && $customer->last_order_at
            ? Carbon::parse($customer->last_order_at)->toISOString()
            : null;

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
