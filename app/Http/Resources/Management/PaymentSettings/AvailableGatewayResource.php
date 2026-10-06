<?php

namespace App\Http\Resources\Management\PaymentSettings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-11 — a platform gateway the business has not connected yet; the
 * "connect a gateway" picker renders these.
 */
class AvailableGatewayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
        ];
    }
}
