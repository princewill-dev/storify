<?php

namespace App\Http\Resources\Management\Product;

use App\Models\Product;
use Illuminate\Http\Request;

/**
 * The detailed base ProductController row — `payload($product, detailed: true)`
 * verbatim: the base row plus the write-facing columns, the variant/file/image
 * rows and nothing else. The extra keys are appended in the order the inline
 * payload appended them, after `created_at`, so the exact-JSON assertions on
 * the create/update/show responses keep reading the same shape.
 *
 * Deliberately separate from App\Http\Resources\Management\ProductDetailResource
 * (the WS-14 slice behind the shared routes, served by ProductFormController):
 * that payload adds relation objects, unit names, stock math and disk checks.
 * The two are not interchangeable.
 *
 * @property Product $resource
 */
final class ProductDetailResource extends ProductResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $product = $this->resource;

        $data = parent::toArray($request);

        $data['description'] = $product->description;
        $data['cost_price'] = $product->cost_price !== null ? (float) $product->cost_price : null;
        $data['bulk_quantity'] = $product->bulk_quantity;
        $data['bulk_price'] = $product->bulk_price !== null ? (float) $product->bulk_price : null;
        $data['discount_percentage'] = $product->discount_percentage !== null ? (float) $product->discount_percentage : null;
        $data['download_limit'] = $product->download_limit;
        $data['download_expiry_days'] = $product->download_expiry_days;
        $data['variants'] = $product->variants->map(fn ($variant) => [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'amount' => (float) $variant->amount,
            'quantity' => (int) $variant->quantity,
            'status' => $variant->status,
        ])->values()->all();
        $data['files'] = $product->files->map(fn ($file) => [
            'id' => $file->id,
            'original_name' => $file->original_name,
            'size' => (int) $file->size,
            'formatted_size' => $file->formatted_size,
        ])->values()->all();
        $data['images'] = $product->images->map(fn ($image) => [
            'id' => $image->id,
            'url' => asset('storage/'.$image->path),
            'is_primary' => (bool) $image->is_primary,
        ])->values()->all();

        return $data;
    }
}
