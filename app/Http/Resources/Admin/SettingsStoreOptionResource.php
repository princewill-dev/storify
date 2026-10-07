<?php

namespace App\Http\Resources\Admin;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-02 (admin) — one row of the homepage store picker.
 *
 * The rows come from `SettingsRepository::storeOptions()`, which keeps a saved
 * but since-deleted store in the list; `status` is what lets the form show the
 * stored selection instead of blanking it.
 *
 * @property-read Store $resource
 */
final class SettingsStoreOptionResource extends JsonResource
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
