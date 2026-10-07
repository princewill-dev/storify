<?php

namespace App\Http\Resources\Admin;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-16 (admin console) — one row of the support inbox's store filter.
 *
 * The rows come from `SupportMessageRepository::storeOptions()`, which only
 * lists stores that actually hold messages so a platform-wide dropdown stays
 * relevant. A deleted store stays in the list — its messages are still owed
 * an answer — and `status` is what lets the SPA mark it as such.
 *
 * @property-read Store $resource
 */
final class SupportMessageStoreOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Store $store */
        $store = $this->resource;

        return [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'status' => $store->status,
            'business_name' => $store->business?->name,
        ];
    }
}
