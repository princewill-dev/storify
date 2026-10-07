<?php

namespace App\Http\Resources\Management\Dashboard;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-28 — one row of the low-stock panel.
 *
 * Variant-driven products report their variant total (the `variant_stock`
 * sum the panel's query adds), exactly like the products-list filter and
 * per-row `low_stock` flag; a plain product reports its own quantity.
 *
 * @property-read Product $resource
 */
final class LowStockProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        return [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'name' => $product->name,
            'store' => $product->store?->name,
            'quantity' => (int) ($product->has_variants ? ($product->variant_stock ?? 0) : $product->quantity),
        ];
    }
}
