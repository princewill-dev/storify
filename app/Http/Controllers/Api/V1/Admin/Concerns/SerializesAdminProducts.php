<?php

namespace App\Http\Controllers\Api\V1\Admin\Concerns;

use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * WS-15 — one serialization for the admin product & category catalogue
 * (platform list, store-scoped list, detail, post-mutation echoes and the
 * category rows the directory renders).
 *
 * DECISION (roadmap §18: "decide one shared serializer strategy with
 * Api\V1\Management\ProductController"): the management controller belongs to
 * another workstream and must not be edited from here, so the admin app gets
 * exactly one admin-side serializer — this trait — instead of a slightly
 * different payload per endpoint. Its field names are identical to the
 * management payload wherever the two overlap (`id`, `product_code`, `name`,
 * `slug`, `brand`, `amount`, `quantity`, `status`, `featured`, `store_id`,
 * `category_id`, `image_url`, `created_at`), so consolidating the two into one
 * class later is a move rather than a migration. The additions are the legacy
 * admin scanning columns (store/category names) and the server-computed price
 * cell: the variant min–max range, and the `₦1,000.00 -> ₦900.00 (-10%)`
 * discount arrow the audit describes.
 *
 * Money: product amounts live as naira decimals on this schema (no column is
 * denominated in kobo yet), so the trait converts each stored value to integer
 * minor units once and does every subsequent calculation in integers —
 * discount math included. Floats are never used for arithmetic.
 */
trait SerializesAdminProducts
{
    /**
     * Currency symbols keyed by id, resolved once so a page of variants does
     * not re-query the currencies table per row.
     *
     * @var array<int, string>|null
     */
    private ?array $adminProductCurrencySymbols = null;

    /**
     * The list/detail row both the platform list and the store-scoped list
     * render. Callers eager-load `store`, `category`, `currency`, `images`
     * and `variants` so this never lazy-loads per row.
     *
     * @return array<string, mixed>
     */
    protected function productListRow(Product $product): array
    {
        return [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'name' => $product->name,
            'slug' => $product->slug,
            'brand' => $product->brand,
            'status' => $product->status,
            'featured' => (bool) $product->featured,
            'has_variants' => (bool) $product->has_variants,
            'is_digital' => (bool) $product->is_digital,
            'quantity' => (int) $product->quantity,
            'store_id' => $product->store_id,
            'store' => $product->store?->name,
            'store_public_id' => $product->store?->store_id,
            'store_slug' => $product->store?->slug,
            'category_id' => $product->category_id,
            'category' => $product->category?->name,
            'currency' => $this->currencyPayload($product),
            'amount' => $this->decimalToMoney($product->amount),
            'discount_percentage' => $product->discount_percentage !== null ? (float) $product->discount_percentage : null,
            'final_amount' => $this->discountedAmount($product),
            'display_price' => $this->displayPrice($product),
            'variant_price' => $this->variantPrice($product),
            'image_url' => $product->primaryImage()?->path
                ? asset('storage/'.$product->primaryImage()->path)
                : null,
            'views' => (int) ($product->views ?? 0),
            'created_at' => $product->created_at?->toISOString(),
            'updated_at' => $product->updated_at?->toISOString(),
        ];
    }

    /**
     * The full record behind the product detail tabs and the edit form —
     * everything the legacy Overview / Variants / Images & Meta tabs showed.
     *
     * @return array<string, mixed>
     */
    protected function productDetailPayload(Product $product): array
    {
        return array_merge($this->productListRow($product), [
            'description' => $product->description,
            'tags' => $product->tags,
            'color' => $product->color,
            'size' => $product->size !== null ? (float) $product->size : null,
            'size_unit_id' => $product->size_unit_id,
            'size_unit' => $product->sizeUnit?->name,
            'weight' => $product->weight !== null ? (float) $product->weight : null,
            'weight_unit_id' => $product->weight_unit_id,
            'weight_unit' => $product->weightUnit?->name,
            'cost_price' => $product->cost_price !== null ? (float) $product->cost_price : null,
            'stock_quantity' => $product->stock_quantity !== null ? (int) $product->stock_quantity : null,
            'sold_quantity' => $product->soldQuantity(),
            'bulk_quantity' => $product->bulk_quantity !== null ? (int) $product->bulk_quantity : null,
            'bulk_price' => $product->bulk_price !== null ? (float) $product->bulk_price : null,
            'cod_available' => (bool) $product->cod_available,
            'is_taxable' => (bool) $product->is_taxable,
            'download_limit' => $product->download_limit,
            'download_expiry_days' => $product->download_expiry_days,
            'files_count' => $product->relationLoaded('files') ? $product->files->count() : null,
            'images' => $product->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => asset('storage/'.$image->path),
                'path' => $image->path,
                'is_primary' => (bool) $image->is_primary,
                'position' => (int) $image->position,
            ])->values()->all(),
            'variants' => $product->variants->map(fn (ProductVariant $variant) => [
                'id' => $variant->id,
                'variant_code' => $variant->variant_code,
                'sku' => $variant->sku,
                'size' => $variant->size !== null ? (float) $variant->size : null,
                'size_unit_id' => $variant->size_unit_id,
                'size_unit' => $variant->sizeUnit?->name,
                'weight' => $variant->weight !== null ? (float) $variant->weight : null,
                'weight_unit_id' => $variant->weight_unit_id,
                'weight_unit' => $variant->weightUnit?->name,
                'color' => $variant->color,
                'quantity' => (int) $variant->quantity,
                'amount' => $this->decimalToMoney($variant->amount),
                'currency_id' => $variant->currency_id,
                'currency' => $this->currencySymbolForId($variant->currency_id),
                'status' => $variant->status,
                'featured' => (bool) $variant->featured,
            ])->values()->all(),
        ]);
    }

    /**
     * The legacy Amount cell: a min–max range for variant products, or the
     * strike-through discount arrow for a reduced single-SKU product.
     */
    protected function displayPrice(Product $product): string
    {
        if ($product->has_variants) {
            return $this->variantPrice($product)['display'] ?? '—';
        }

        $symbol = $product->currency?->symbol ?? $this->currencySymbolForId($product->currency_id);
        $base = $symbol.$this->formatMinorUnits($this->minorUnits($product->amount));
        $discountBasisPoints = $this->basisPoints($product->discount_percentage);

        if ($discountBasisPoints <= 0) {
            return $base;
        }

        $finalMinor = $this->minorUnits($product->amount)
            - intdiv($this->minorUnits($product->amount) * $discountBasisPoints + 5000, 10000);

        $percent = rtrim(rtrim(number_format((float) $product->discount_percentage, 2, '.', ''), '0'), '.');

        return $base.' -> '.$symbol.$this->formatMinorUnits($finalMinor).' (-'.$percent.'%)';
    }

    /**
     * The variant min–max block: null when the product has no variants.
     *
     * @return array{min: float, max: float, symbol: string, display: string}|null
     */
    protected function variantPrice(Product $product): ?array
    {
        $variants = $product->relationLoaded('variants')
            ? $product->variants
            : $product->variants()->get();

        if ($variants->isEmpty()) {
            return null;
        }

        $sorted = $variants->sortBy(fn (ProductVariant $variant) => $this->minorUnits($variant->amount));
        /** @var ProductVariant $min */
        $min = $sorted->first();
        /** @var ProductVariant $max */
        $max = $sorted->last();

        $minMinor = $this->minorUnits($min->amount);
        $maxMinor = $this->minorUnits($max->amount);
        $minSymbol = $this->currencySymbolForId($min->currency_id);
        $maxSymbol = $this->currencySymbolForId($max->currency_id);

        $display = $minSymbol.$this->formatMinorUnits($minMinor);

        if ($maxMinor !== $minMinor) {
            $display .= ' - '.$maxSymbol.$this->formatMinorUnits($maxMinor);
        }

        return [
            'min' => $this->minorToMoney($minMinor),
            'max' => $this->minorToMoney($maxMinor),
            'symbol' => $minSymbol,
            'display' => $display,
        ];
    }

    /**
     * @return array{id: int, code: string|null, symbol: string}|null
     */
    protected function currencyPayload(Product $product): ?array
    {
        if (! $product->currency_id) {
            return null;
        }

        return [
            'id' => (int) $product->currency_id,
            'code' => $product->currency?->code,
            'symbol' => $product->currency?->symbol ?? $this->currencySymbolForId($product->currency_id),
        ];
    }

    /**
     * The discounted unit price — null when no discount applies.
     */
    protected function discountedAmount(Product $product): ?float
    {
        $basisPoints = $this->basisPoints($product->discount_percentage);

        if ($basisPoints <= 0) {
            return null;
        }

        $minor = $this->minorUnits($product->amount);

        return $this->minorToMoney($minor - intdiv($minor * $basisPoints + 5000, 10000));
    }

    /**
     * The naira money value the payloads exchange with the SPA. Product and
     * variant amount columns carry no decimal cast, so the database hands back
     * strings ("1000.00"); the management payload and the admin edit form both
     * speak this stored decimal (no column is denominated in kobo yet), so the
     * value is passed through as a float and never used for arithmetic —
     * calculations go through {@see minorUnits()} instead.
     */
    protected function decimalToMoney(mixed $amount): ?float
    {
        return $amount === null ? null : (float) $amount;
    }

    /**
     * Stored decimals are naira; convert to integer minor units once so every
     * calculation after this point is integer arithmetic.
     */
    protected function minorUnits(mixed $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }

    protected function minorToMoney(int $minor): float
    {
        return $minor / 100;
    }

    /**
     * Format integer minor units without ever floating the money value.
     */
    protected function formatMinorUnits(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);

        return $sign.number_format(intdiv($absolute, 100)).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * A percentage as basis points (10.5% → 1050), so it multiplies against
     * integer money instead of the other way round.
     */
    protected function basisPoints(mixed $percentage): int
    {
        return (int) round(((float) ($percentage ?? 0)) * 100);
    }

    protected function currencySymbolForId(?int $id): string
    {
        if ($this->adminProductCurrencySymbols === null) {
            $this->adminProductCurrencySymbols = Currency::query()->pluck('symbol', 'id')->all();
        }

        return (string) ($this->adminProductCurrencySymbols[$id] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function categoryPayload(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'status' => $category->status,
            'store_id' => $category->store_id,
            'store' => $category->store?->name,
            'store_public_id' => $category->store?->store_id,
            'parent_id' => $category->parent_id,
            'parent' => $category->parent?->name,
            'products_count' => $category->products_count ?? null,
            'created_at' => $category->created_at?->toISOString(),
            'updated_at' => $category->updated_at?->toISOString(),
        ];
    }
}
