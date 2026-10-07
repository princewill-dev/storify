<?php

namespace App\Http\Resources\Management;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The customer row shape shared by the list and the update/suspend/activate
 * responses. `orders_count` is only filled when the list's withCount loaded
 * it; elsewhere it stays null, exactly as the controller's payload() emitted
 * it. Field names, order and types must not change — tests assert them.
 */
class CustomerResource extends JsonResource
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
            'orders_count' => $customer->orders_count ?? null,
            'created_at' => $customer->created_at?->toISOString(),
        ];
    }
}
