<?php

namespace App\Http\Resources\Management\Service;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The WS-30 service list's store filter list — the controller's inline store
 * map verbatim, including its key order (`id`, `store_id`, `name`).
 *
 * Deliberately not App\Http\Resources\Management\StoreOptionResource, whose
 * contract emits `id`, `name`, `store_id`: the SPA reads this list for both
 * the store filter and the create-a-store empty state, and the exact-JSON
 * assertions keep the order the inline map produced.
 *
 * @property Store $resource
 */
final class StoreOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'store_id' => $this->resource->store_id,
            'name' => $this->resource->name,
        ];
    }
}
