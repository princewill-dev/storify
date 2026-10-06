<?php

namespace App\Http\Resources\Management;

use App\Models\Product;
use App\Models\ProductFile;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\Request;

/**
 * WS-14 — the widened detail payload the edit view (and any future detail
 * page) renders: names for every relation, dimensions and units, stock math,
 * tags, views and full variant/file/image rows on top of the list payload.
 */
final class ProductDetailResource extends ProductResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        $data = parent::toArray($request);

        $data['description'] = $product->description;
        $data['color'] = $product->color;
        $data['size'] = $product->size !== null ? (float) $product->size : null;
        $data['size_unit_id'] = $product->size_unit_id;
        $data['size_unit_name'] = $product->sizeUnit?->name;
        $data['weight'] = $product->weight !== null ? (float) $product->weight : null;
        $data['weight_unit_id'] = $product->weight_unit_id;
        $data['weight_unit_name'] = $product->weightUnit?->name;
        $data['cost_price'] = $product->cost_price !== null ? (float) $product->cost_price : null;
        $data['average_cost_kobo'] = (int) ($product->average_cost_kobo ?? 0);
        $data['bulk_quantity'] = $product->bulk_quantity !== null ? (int) $product->bulk_quantity : null;
        $data['bulk_price'] = $product->bulk_price !== null ? (float) $product->bulk_price : null;
        $data['download_limit'] = $product->download_limit;
        $data['download_expiry_days'] = $product->download_expiry_days;
        // Legacy showed a views counter on the detail screens.
        $data['views'] = (int) ($product->views ?? 0);
        $data['currency'] = $product->currency ? [
            'id' => $product->currency->id,
            'code' => $product->currency->code,
            'symbol' => $product->currency->symbol,
        ] : null;
        $data['store'] = $product->store ? [
            'id' => $product->store->id,
            'store_id' => $product->store->store_id,
            'name' => $product->store->name,
            'slug' => $product->store->slug,
        ] : null;
        $data['warehouse'] = $product->warehouse ? [
            'id' => $product->warehouse->id,
            'warehouse_code' => $product->warehouse->warehouse_code,
            'name' => $product->warehouse->name,
        ] : null;
        $data['section'] = $product->section ? [
            'id' => $product->section->id,
            'section_code' => $product->section->section_code,
            'name' => $product->section->name,
        ] : null;
        $data['category'] = $product->category ? [
            'id' => $product->category->id,
            'name' => $product->category->name,
        ] : null;
        $data['variants'] = $product->variants
            ->map(fn (ProductVariant $variant) => $this->variantRow($variant))
            ->values()
            ->all();
        $data['files'] = $product->files
            ->map(fn (ProductFile $file) => $this->fileRow($file))
            ->values()
            ->all();
        $data['images'] = $product->images
            ->map(fn (ProductImage $image) => $this->imageRow($image))
            ->values()
            ->all();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function variantRow(ProductVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'variant_code' => $variant->variant_code,
            'sku' => $variant->sku,
            'size' => $variant->size !== null ? (float) $variant->size : null,
            'size_unit_id' => $variant->size_unit_id,
            'size_unit_name' => $variant->sizeUnit?->name,
            'weight' => $variant->weight !== null ? (float) $variant->weight : null,
            'weight_unit_id' => $variant->weight_unit_id,
            'weight_unit_name' => $variant->weightUnit?->name,
            'color' => $variant->color,
            'quantity' => (int) $variant->quantity,
            'amount' => (float) $variant->amount,
            'currency_id' => $variant->currency_id,
            'status' => $variant->status,
            'featured' => (bool) $variant->featured,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fileRow(ProductFile $file): array
    {
        return [
            'id' => $file->id,
            'original_name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size' => (int) $file->size,
            'formatted_size' => $file->formatted_size,
            'is_primary' => (bool) $file->is_primary,
            'position' => (int) $file->position,
            // Lesson from the audit's digital-file gap: tell the business
            // when a stored file has vanished from disk.
            'exists_on_disk' => $file->existsOnDisk(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function imageRow(ProductImage $image): array
    {
        return [
            'id' => $image->id,
            'url' => asset('storage/'.$image->path),
            'is_primary' => (bool) $image->is_primary,
            'position' => (int) $image->position,
        ];
    }
}
