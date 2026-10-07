<?php

namespace App\Http\Resources\Management;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-33 — one row of the support inbox's store filter.
 *
 * The rows come from the controller's `accessibleStores()`: the caller's
 * reachable stores minus soft-deleted ones, name-ordered. Kept key-for-key
 * and in the same order as the inline array it replaces (`id`, `store_id`,
 * `name`) — exact-JSON consumers assert field order, and the shared
 * StoreOptionResource emits them in a different one, so it could not carry
 * this list.
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
        ];
    }
}
