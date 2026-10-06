<?php

namespace App\Http\Resources\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\SerializesAdminProducts;
use App\Models\Category;
use App\Models\Currency;
use App\Models\SizeUnit;
use App\Models\Store;
use App\Models\WeightUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-15 (admin console) — the create/edit form dropdowns.
 *
 * Legacy loaded these per form action; one endpoint keeps the SPA form's data
 * in a single round trip. The controller hands over what ProductRepository
 * read; this resource only shapes it, so the query side and the response side
 * stay separate — the same split the catalogue itself uses between
 * ProductRepository and {@see SerializesAdminProducts}.
 *
 * The image limit travels in from the controller (one source: the write
 * request's IMAGE_MAX_KB) rather than being duplicated here.
 *
 * @property-read array{
 *     stores: Collection<int, Store>,
 *     categories: Collection<int, Category>,
 *     currencies: Collection<int, Currency>,
 *     size_units: Collection<int, SizeUnit>,
 *     weight_units: Collection<int, WeightUnit>,
 *     default_currency_id: int|null,
 * } $resource
 */
final class ProductFormOptionsResource extends JsonResource
{
    /**
     * @param  array{
     *     stores: Collection<int, Store>,
     *     categories: Collection<int, Category>,
     *     currencies: Collection<int, Currency>,
     *     size_units: Collection<int, SizeUnit>,
     *     weight_units: Collection<int, WeightUnit>,
     *     default_currency_id: int|null,
     * }  $options
     */
    public function __construct(array $options, private readonly int $imageMaxKb)
    {
        parent::__construct($options);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'stores' => collect($this->resource['stores'])->map(fn (Store $store) => [
                'id' => $store->id,
                'store_id' => $store->store_id,
                'name' => $store->name,
                'status' => $store->status,
                'business' => $store->business?->name,
                'business_code' => $store->business?->business_code,
            ])->values()->all(),
            'categories' => collect($this->resource['categories'])->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'store_id' => $category->store_id,
                'status' => $category->status,
            ])->values()->all(),
            'currencies' => collect($this->resource['currencies'])->map(fn (Currency $currency) => [
                'id' => $currency->id,
                'name' => $currency->name,
                'code' => $currency->code,
                'symbol' => $currency->symbol,
                'is_default' => (bool) $currency->is_default,
            ])->values()->all(),
            'size_units' => collect($this->resource['size_units'])->map(fn (SizeUnit $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'code' => $unit->code,
            ])->values()->all(),
            'weight_units' => collect($this->resource['weight_units'])->map(fn (WeightUnit $unit) => [
                'id' => $unit->id,
                'name' => $unit->name,
                'code' => $unit->code,
            ])->values()->all(),
            'default_currency_id' => $this->resource['default_currency_id'],
            'image_max_kb' => $this->imageMaxKb,
        ];
    }
}
