<?php

namespace App\Http\Resources\Management;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-19 — one row of the store detail "Customers" tab.
 *
 * Field names, order and types must not change: the tab's tests assert this
 * shape. `orders_count` is the per-store withCount the list always loads and
 * is emitted as an int, 0 when absent — unlike CustomerResource, whose
 * `orders_count` stays nullable for the base customers list.
 */
final class StoreCustomerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Customer $customer */
        $customer = $this->resource;

        return [
            'id' => $customer->id,
            'account_id' => $customer->account_id,
            'name' => $customer->full_name,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'status' => strtolower($customer->status),
            'orders_count' => (int) ($customer->orders_count ?? 0),
            'created_at' => $customer->created_at?->toISOString(),
        ];
    }
}
