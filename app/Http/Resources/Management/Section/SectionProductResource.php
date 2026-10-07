<?php

namespace App\Http\Resources\Management\Section;

use App\Models\Product;
use App\Support\Money\Naira;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-36 — a product row of the section screens: the controller's inline
 * `productRow()` verbatim, field names, order and types included.
 *
 * `amount_kobo` is passed through the same exact-parsing contract the
 * controller's private `nairaToKobo()` used (`Naira::koboFromLenient()` — see
 * SectionStatsResource). The show endpoint renders plain rows; the
 * available-products picker echoes the current section on each row
 * (`withSection: true`, the controller's named argument) so the UI can show
 * that an assignment would move a product out of its present zone.
 *
 * @property Product $resource
 */
final class SectionProductResource extends JsonResource
{
    private readonly bool $withSection;

    /**
     * Only a real boolean turns the section echo on. Laravel's
     * `ResourceCollection` wraps items through `collect()->mapInto()`, which
     * calls the constructor as `new static($item, $key)` — so the second
     * argument of a `::collection()` call is the row's integer key, not a
     * flag. Coercing it would add `section_id`/`section_name` to every row
     * after the first; the controller's picker passes `withSection: true` by
     * name.
     */
    public function __construct(Product $product, mixed $withSection = false)
    {
        parent::__construct($product);

        $this->withSection = is_bool($withSection) && $withSection;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        $row = [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'name' => $product->name,
            'amount_kobo' => Naira::koboFromLenient($product->amount),
            'quantity' => (int) $product->quantity,
            'stock_quantity' => $product->stock_quantity !== null ? (int) $product->stock_quantity : null,
            'stock_percentage' => $product->stockPercentage(),
            'status' => $product->status,
            'is_digital' => (bool) $product->is_digital,
            'warehouse_id' => $product->warehouse_id,
            'store_name' => $product->store?->name,
            'image_url' => $product->primaryImage()?->path ? asset('storage/'.$product->primaryImage()->path) : null,
        ];

        if ($this->withSection) {
            $row['section_id'] = $product->section_id;
            $row['section_name'] = $product->section?->name;
        }

        return $row;
    }
}
