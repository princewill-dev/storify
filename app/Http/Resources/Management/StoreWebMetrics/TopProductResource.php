<?php

namespace App\Http\Resources\Management\StoreWebMetrics;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-35 — one row of the top-products-by-views list.
 *
 * Field names, types and order are the controller's inline map moved
 * verbatim; the endpoint's tests assert this shape. `views` is the raw
 * lifetime counter the repository ordered by.
 *
 * @property Product $resource
 */
final class TopProductResource extends JsonResource
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
            'views' => (int) $product->views,
            'status' => $product->status,
            'is_digital' => (bool) $product->is_digital,
        ];
    }
}
