<?php

namespace App\Http\Resources\Management\Transfer;

use App\Models\Product;
use App\Models\StockLocation;
use App\Models\Store;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-15 — the create grid payload: `location` plus the merged product list.
 *
 * Rows from the two sources dispatch reads — existing stock-location rows and
 * the `Product.quantity` fallback — are rendered through the same
 * SourceProductResource so they cannot disagree, then sorted by name.
 *
 * @property null $resource
 */
final class SourceProductsPayloadResource extends JsonResource
{
    /**
     * @param  Collection<int, StockLocation>  $rows
     * @param  Collection<int, Product>  $fallback
     */
    public function __construct(
        private readonly string $class,
        private readonly Store|Warehouse $location,
        private readonly Collection $rows,
        private readonly Collection $fallback,
    ) {
        parent::__construct(null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $products = $this->rows
            ->map(fn (StockLocation $row) => (new SourceProductResource(
                $row->product,
                $row->productVariant,
                (int) $row->quantity,
                $row->product_variant_id ? (int) $row->product_variant_id : null,
            ))->resolve($request))
            ->merge($this->fallback->map(
                fn (Product $product) => (new SourceProductResource($product, null, (int) $product->quantity, null))->resolve($request),
            ))
            ->sortBy('name')
            ->values();

        return [
            'location' => LocationRefResource::for($this->class, $this->location, $request),
            'products' => $products->all(),
        ];
    }
}
