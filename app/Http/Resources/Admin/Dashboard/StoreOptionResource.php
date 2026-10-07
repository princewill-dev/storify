<?php

namespace App\Http\Resources\Admin\Dashboard;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS7 — one row of the dashboard's store selector, legacy's `$allStores`.
 *
 * @property-read Store $resource
 */
final class StoreOptionResource extends JsonResource
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
        ];
    }
}
