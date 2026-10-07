<?php

namespace App\Http\Resources\Management\Service;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A WS-30 ServiceController list row — the controller's inline `summary()`
 * verbatim, field names, order and types included.
 *
 * Tests read this payload with exact-JSON expectations, so nothing here is
 * "tidied": `amount` stays a float (the legacy decimal-naira column, returned
 * as-is, not run through any kobo conversion) and `primary_image` stays an
 * absolute asset URL.
 *
 * @property Service $resource
 */
class ServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $service = $this->resource;
        $primary = $service->primaryImage();

        return [
            'id' => $service->id,
            'service_code' => $service->service_code,
            'name' => $service->name,
            'slug' => $service->slug,
            'description' => $service->description,
            'amount' => (float) $service->amount,
            'status' => $service->status,
            'currency' => $service->currency ? [
                'id' => $service->currency->id,
                'code' => $service->currency->code,
                'symbol' => $service->currency->symbol,
            ] : null,
            'store' => $service->store ? [
                'id' => $service->store->id,
                'store_id' => $service->store->store_id,
                'name' => $service->store->name,
            ] : null,
            'primary_image' => $primary?->path ? asset('storage/'.$primary->path) : null,
            'images_count' => $service->images->count(),
            'created_at' => $service->created_at?->toISOString(),
        ];
    }
}
