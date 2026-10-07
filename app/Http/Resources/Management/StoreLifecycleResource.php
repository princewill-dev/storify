<?php

namespace App\Http\Resources\Management;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-04 — the store row the suspend/activate responses render, fed a `fresh()`
 * row by the controller.
 *
 * This is the controller's private lifecyclePayload() array, kept key-for-key
 * and in the same order: exact-JSON consumers assert the field order, so the
 * keys may not be sorted or renamed.
 *
 * @property Store $resource
 */
class StoreLifecycleResource extends JsonResource
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
            'status' => $this->resource->status,
        ];
    }
}
