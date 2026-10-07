<?php

namespace App\Http\Resources\Management\Product;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A base ProductController row — the controller's inline `payload()` verbatim,
 * field names, order and types included. Tests assert exact JSON, so nothing
 * here is "tidied": `amount` stays a float, ids stay as stored, `warehouse` is
 * the relation's name (lazily loaded where the caller did not eager-load it,
 * exactly as the inline payload read it) and the created timestamp stays an
 * ISO-8601 string.
 *
 * Deliberately separate from App\Http\Resources\Management\ProductResource
 * (the WS-14 slice behind the shared routes, served by ProductFormController):
 * that payload carries tags, display amounts, stock bands, currency and more.
 * The two are not interchangeable.
 *
 * @property Product $resource
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        return [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'name' => $product->name,
            'slug' => $product->slug,
            'brand' => $product->brand,
            'amount' => (float) $product->amount,
            'quantity' => (int) $product->quantity,
            'is_digital' => (bool) $product->is_digital,
            'is_taxable' => (bool) $product->is_taxable,
            'status' => $product->status,
            'featured' => (bool) $product->featured,
            'store_id' => $product->store_id,
            'warehouse_id' => $product->warehouse_id,
            'warehouse' => $product->warehouse?->name,
            'category_id' => $product->category_id,
            'image_url' => $product->primaryImage()?->path
                ? asset('storage/'.$product->primaryImage()->path)
                : null,
            'created_at' => $product->created_at?->toISOString(),
        ];
    }
}
