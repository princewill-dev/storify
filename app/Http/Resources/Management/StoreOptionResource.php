<?php

namespace App\Http\Resources\Management;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-18 — a store filter option, scoped exactly like the rows it filters.
 *
 * @property Store $resource
 */
class StoreOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'store_id' => $this->resource->store_id,
        ];
    }
}
