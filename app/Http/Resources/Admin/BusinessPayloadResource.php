<?php

namespace App\Http\Resources\Admin;

use App\Models\Business;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The payload Api\V1\Admin\BusinessController's private `payload()` served
 * inline before this layer was extracted: the directory row, with
 * `detailed()` adding the currency + stores blocks the show endpoint needs.
 *
 * The shape is frozen — field names, types and ORDER are asserted by
 * exact-JSON tests. In particular the three `*_count` fields stay `null`
 * unless the model already carries count attributes: index runs withCount(),
 * while suspend/activate render `fresh()` and must not count. Do not add the
 * loadCount-if-null that the newer `BusinessResource` uses here, and do not
 * reorder keys.
 *
 * @property-read Business $resource
 */
final class BusinessPayloadResource extends JsonResource
{
    private bool $detailed = false;

    public function detailed(bool $detailed = true): static
    {
        $this->detailed = $detailed;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Business $business */
        $business = $this->resource;

        $data = [
            'id' => $business->id,
            'name' => $business->name,
            'business_code' => $business->business_code,
            'status' => $business->status,
            'owner' => $business->owner ? [
                'id' => $business->owner->id,
                'name' => $business->owner->name,
                'email' => $business->owner->email,
                'account_code' => $business->owner->account_code,
            ] : null,
            'plan' => $business->activeSubscription?->subscriptionPlan?->name,
            'stores_count' => $business->stores_count ?? null,
            'warehouses_count' => $business->warehouses_count ?? null,
            'users_count' => $business->users_count ?? null,
            'created_at' => $business->created_at?->toISOString(),
        ];

        if ($this->detailed) {
            $data['currency'] = $business->currency;
            $data['stores'] = $business->stores->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'status' => $store->status,
                'store_type' => $store->store_type,
            ])->values()->all();
        }

        return $data;
    }
}
