<?php

namespace App\Http\Resources\Admin;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-9 (admin console) — the platform customer directory row.
 *
 * Every key, type and position the previous inline payload emitted is
 * preserved so exact-JSON consumers keep working: `orders_count` is the
 * `withCount` alias when the directory eager-loaded it (null otherwise), and
 * `status` is lowercased because the schema stores the uppercase
 * `Customer::STATUS_*` spelling while payloads speak the management API's
 * lowercase one.
 *
 * @property-read Customer $resource
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
            'location' => $customer->location,
            'country' => $customer->country,
            'status' => strtolower($customer->status),
            'email_verified' => $customer->hasVerifiedEmail(),
            'orders_count' => isset($customer->orders_count) ? (int) $customer->orders_count : null,
            'business' => $customer->business?->name,
            'business_code' => $customer->business?->business_code,
            'business_id' => $customer->business_id,
            'last_login' => $customer->last_login?->toISOString(),
            'created_at' => $customer->created_at?->toISOString(),
            'updated_at' => $customer->updated_at?->toISOString(),
        ];
    }
}
