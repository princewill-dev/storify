<?php

namespace App\Http\Resources\Management\Transfer;

use App\Models\StockLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-15 — one stock-location row of the `stock-locations` read.
 *
 * `image_url` mirrors the create grid: the primary image's stored path through
 * `asset('storage/...')`, null when the product carries none.
 *
 * @property StockLocation $resource
 */
final class StockLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var StockLocation $row */
        $row = $this->resource;

        return [
            'id' => $row->id,
            'product_id' => $row->product_id,
            'product_variant_id' => $row->product_variant_id,
            'product' => [
                'id' => $row->product?->id,
                'name' => $row->product?->name ?? 'Unknown product',
                'product_code' => $row->product?->product_code,
                'image_url' => $row->product?->primaryImage()?->path
                    ? asset('storage/'.$row->product->primaryImage()->path)
                    : null,
            ],
            'variant_label' => $row->productVariant?->variant_code,
            'quantity' => (int) $row->quantity,
            'min_quantity' => (int) $row->min_quantity,
        ];
    }
}
