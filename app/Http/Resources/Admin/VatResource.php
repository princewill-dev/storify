<?php

namespace App\Http\Resources\Admin;

use App\Models\Vat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-12 — the platform VAT-rate row.
 *
 * Field names, types and order match the payload this endpoint has always
 * returned (the tests assert the exact shape): `percentage` is cast to float
 * at the edge — PHP encodes a whole-number float as a JSON int, which the
 * existing assertions depend on — `active` is a boolean, and the timestamps
 * are ISO-8601 strings or null.
 *
 * @property-read Vat $resource
 */
class VatResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Vat $vat */
        $vat = $this->resource;

        return [
            'id' => $vat->id,
            'percentage' => (float) $vat->percentage,
            'active' => (bool) $vat->active,
            'effective_at' => $vat->effective_at?->toISOString(),
            'created_at' => $vat->created_at?->toISOString(),
        ];
    }
}
