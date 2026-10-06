<?php

namespace App\Http\Resources\Management\Invoice;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-21 — a store picker row for the create/edit invoice form.
 *
 * Inactive stores stay in the list, flagged by `status`, so editing a draft
 * that points at one never loses its selection.
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
            'name' => $this->resource->name,
            'store_id' => $this->resource->store_id,
            'status' => $this->resource->status,
        ];
    }
}
