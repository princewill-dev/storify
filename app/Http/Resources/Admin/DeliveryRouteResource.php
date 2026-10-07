<?php

namespace App\Http\Resources\Admin;

use App\Models\DeliveryRoute;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-17 — the platform delivery-route row.
 *
 * Field names, types and order match the payload this endpoint has always
 * returned (exact-JSON assertions depend on them): `fee` stays integer kobo —
 * the exact contract checkout consumes — and `fee_ngn` is the same amount as
 * an exact 2dp NGN string for the edit form. `Naira::decimalFromKobo()` is
 * integer arithmetic only, so a 123457 kobo route renders "1234.57" rather
 * than suffering the legacy edit modal's `(int) ($fee / 100)` truncation.
 *
 * @property-read DeliveryRoute $resource
 */
class DeliveryRouteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DeliveryRoute $route */
        $route = $this->resource;

        return [
            'id' => $route->id,
            'store_id' => $route->store_id,
            'country' => $route->country,
            'state' => $route->state,
            'area' => $route->area,
            // kobo — the exact contract checkout consumes.
            'fee' => (int) $route->fee,
            // the same amount as NGN, safe to pre-fill a form with.
            'fee_ngn' => Naira::decimalFromKobo((int) $route->fee),
            'delivery_days' => (int) $route->delivery_days,
            'active' => (bool) $route->active,
            'created_at' => $route->created_at?->toISOString(),
            'updated_at' => $route->updated_at?->toISOString(),
        ];
    }
}
