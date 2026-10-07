<?php

namespace App\Http\Resources\Management\DeliveryRoute;

use App\Models\DeliveryRoute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-04 — a store delivery-route row: the controller's inline map verbatim,
 * field names, order and types included (exact-JSON consumers assert them).
 * `fee` stays integer kobo — the exact contract checkout consumes — and the
 * int/bool casts are what the hand-built array did.
 *
 * Deliberately separate from App\Http\Resources\Admin\DeliveryRouteResource:
 * that platform-console row adds `store_id`, `fee_ngn` and timestamps and is
 * addressable only for platform-wide routes. This one is store-scoped and
 * must not grow those fields nor gain a money conversion; the two are not
 * interchangeable.
 *
 * @property DeliveryRoute $resource
 */
class DeliveryRouteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'country' => $this->resource->country,
            'state' => $this->resource->state,
            'area' => $this->resource->area,
            'fee' => (int) $this->resource->fee,
            'delivery_days' => (int) $this->resource->delivery_days,
            'active' => (bool) $this->resource->active,
        ];
    }
}
