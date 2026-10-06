<?php

namespace App\Http\Resources\Management;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Section;
use App\Models\SizeUnit;
use App\Models\Store;
use App\Models\Warehouse;
use App\Models\WeightUnit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-14 — everything the product form needs to render its pickers in one
 * call: stores, warehouses with their sections, categories, currencies, size
 * and weight units, and the digital-download defaults.
 *
 * The controller hands over what ProductRepository read; this resource only
 * shapes it, so the query side and the response side stay separate.
 *
 * @property-read array{
 *     stores: Collection<int, Store>,
 *     warehouses: Collection<int, Warehouse>,
 *     categories: Collection<int, Category>,
 *     currencies: Collection<int, Currency>,
 *     size_units: Collection<int, SizeUnit>,
 *     weight_units: Collection<int, WeightUnit>,
 *     business_currency: string,
 * } $resource
 */
final class ProductFormOptionsResource extends JsonResource
{
    /**
     * @param  array{
     *     stores: Collection<int, Store>,
     *     warehouses: Collection<int, Warehouse>,
     *     categories: Collection<int, Category>,
     *     currencies: Collection<int, Currency>,
     *     size_units: Collection<int, SizeUnit>,
     *     weight_units: Collection<int, WeightUnit>,
     *     business_currency: string,
     * }  $options
     */
    public function __construct(array $options)
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
                'slug' => $store->slug,
            ])->values()->all(),
            'warehouses' => collect($this->resource['warehouses'])->map(fn (Warehouse $warehouse) => [
                'id' => $warehouse->id,
                'warehouse_code' => $warehouse->warehouse_code,
                'name' => $warehouse->name,
                'city' => $warehouse->city,
                'state' => $warehouse->state,
                'sections' => $warehouse->sections->map(fn (Section $section) => [
                    'id' => $section->id,
                    'section_code' => $section->section_code,
                    'name' => $section->name,
                    'status' => $section->status->value,
                ])->values()->all(),
            ])->values()->all(),
            'categories' => collect($this->resource['categories'])->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'store_id' => $category->store_id,
                'status' => $category->status,
            ])->values()->all(),
            'currencies' => collect($this->resource['currencies'])->map(fn (Currency $currency) => [
                'id' => $currency->id,
                'code' => $currency->code,
                'name' => $currency->name,
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
            'defaults' => [
                'download_limit' => (int) config('digital.default_download_limit', 5),
                'download_expiry_days' => (int) config('digital.default_expiry_days', 7),
            ],
            'low_stock_threshold' => ProductResource::LOW_STOCK_THRESHOLD,
            'business_currency' => $this->resource['business_currency'],
        ];
    }
}
