<?php

namespace App\Http\Resources\Management\Transfer;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-15 — one row of the create grid: a product (or product+variant) with what
 * is available at the chosen source location.
 *
 * Used for both sources the grid reads — an existing stock-location row and
 * the `Product.quantity` fallback for products assigned to the location with
 * no stock-location row yet — so the two cannot render differently.
 *
 * @property Product|null $resource
 */
final class SourceProductResource extends JsonResource
{
    public function __construct(
        ?Product $product,
        private readonly ?ProductVariant $variant,
        private readonly int $available,
        private readonly ?int $variantId,
    ) {
        parent::__construct($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product|null $product */
        $product = $this->resource;

        return [
            'product_id' => $product?->id,
            'product_variant_id' => $this->variantId,
            'name' => $product?->name ?? 'Unknown product',
            'product_code' => $product?->product_code,
            'image_url' => $product?->primaryImage()?->path
                ? asset('storage/'.$product->primaryImage()->path)
                : null,
            'available' => $this->available,
            'variant_label' => $this->variant?->variant_code,
            'has_variants' => (bool) ($product?->has_variants ?? false),
        ];
    }
}
