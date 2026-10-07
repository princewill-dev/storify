<?php

namespace App\Http\Resources\Management;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-19 — a customer row as the list renders it.
 *
 * Field names, types and order are the controller's inline `summary()` moved
 * verbatim; tests assert this payload. `orders_count` is the `withCount`
 * alias when the list eager-loaded it and null when it was not loaded, except
 * on the detail screen where the controller sets it from the SQL aggregate so
 * the edit modal's row agrees with the list.
 */
class CustomerParityResource extends JsonResource
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
            'location' => $customer->location,
            'status' => strtolower($customer->status),
            'email_verified' => $customer->hasVerifiedEmail(),
            'orders_count' => isset($customer->orders_count) ? (int) $customer->orders_count : null,
            'created_at' => $customer->created_at?->toISOString(),
            'updated_at' => $customer->updated_at?->toISOString(),
        ];
    }
}
