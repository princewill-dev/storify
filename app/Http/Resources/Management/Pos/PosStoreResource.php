<?php

namespace App\Http\Resources\Management\Pos;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-17 — the store identity every POS oversight payload embeds (session
 * store, per-store header, enable response).
 *
 * @property Store $resource
 */
final class PosStoreResource extends JsonResource
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
            'pos_enabled' => (bool) $this->resource->pos_enabled,
        ];
    }
}
