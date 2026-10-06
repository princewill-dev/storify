<?php

namespace App\Http\Resources\Management;

use App\Models\Currency;
use App\Models\Product;
use App\Support\Money\Naira;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-14 — a product row as the list and summary payloads render it.
 *
 * Money on this table is the legacy decimal-naira column (`amount`,
 * `cost_price`, `bulk_price`), not kobo: `amount` is converted to integer kobo
 * for the discount math and rendered back as naira floats. The conversion is
 * the same float multiply-and-round the controller used
 * ({@see Naira::koboFromRounded()}); the display division is
 * {@see Naira::floatFromKobo()}, which returns the same values the inline
 * `/ 100` divisions produced.
 *
 * The discount is applied in integer kobo and basis points, never float
 * arithmetic on the money itself. That expression deliberately does not use
 * Naira::percentOfKobo() — the helper truncates where this rounds, so the two
 * disagree on real input.
 *
 * The default currency is resolved lazily through the closure the controller
 * passes (memoized once per request), so a product that carries its own
 * currency — or a list where every row does — never runs the lookup, exactly
 * as the in-controller payload behaved.
 */
class ProductResource extends JsonResource
{
    /** Legacy amber stock warning threshold on the products list. */
    public const LOW_STOCK_THRESHOLD = 10;

    /**
     * @param  (Closure(): string)|null  $defaultCurrency
     */
    public function __construct(
        Product $product,
        protected ?Closure $defaultCurrency = null,
    ) {
        parent::__construct($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        $amountKobo = $product->amount !== null ? Naira::koboFromRounded($product->amount) : null;
        $discountPercent = $product->discount_percentage !== null ? (float) $product->discount_percentage : null;

        $discountKobo = 0;
        if ($amountKobo !== null && $discountPercent) {
            $discountKobo = (int) round($amountKobo * (int) round($discountPercent * 100) / 10000);
        }

        $displayKobo = $amountKobo !== null ? max(0, $amountKobo - $discountKobo) : null;

        $quantity = (int) $product->quantity;
        $isDigital = (bool) $product->is_digital;

        // Variant-driven products hold their stock on the variant rows; the
        // base quantity is only a fallback.
        $variantStock = $product->has_variants && $product->relationLoaded('variants')
            ? (int) $product->variants->sum('quantity')
            : null;

        return [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'name' => $product->name,
            'slug' => $product->slug,
            'brand' => $product->brand,
            'tags' => $product->tags,
            'amount' => $amountKobo !== null ? Naira::floatFromKobo($amountKobo) : null,
            'display_amount' => $displayKobo !== null ? Naira::floatFromKobo($displayKobo) : null,
            'discount_percentage' => $discountPercent,
            'has_discount' => $discountKobo > 0,
            'discount_amount' => Naira::floatFromKobo($discountKobo),
            'quantity' => $quantity,
            'variant_stock' => $variantStock,
            'stock_quantity' => $product->stock_quantity !== null ? (int) $product->stock_quantity : null,
            'sold_quantity' => $product->soldQuantity(),
            'stock_percentage' => $product->stockPercentage(),
            'stock_level' => $this->stockLevel($product),
            'low_stock' => ! $isDigital && ($variantStock ?? $quantity) <= self::LOW_STOCK_THRESHOLD,
            'is_digital' => $isDigital,
            'is_taxable' => (bool) $product->is_taxable,
            'status' => $product->status,
            'featured' => (bool) $product->featured,
            'cod_available' => (bool) $product->cod_available,
            'has_variants' => (bool) $product->has_variants,
            'store_id' => $product->store_id,
            'store_name' => $product->store?->name,
            'warehouse_id' => $product->warehouse_id,
            'warehouse_name' => $product->warehouse?->name,
            'section_id' => $product->section_id,
            'section_name' => $product->section?->name,
            'category_id' => $product->category_id,
            'category_name' => $product->category?->name,
            'currency_id' => $product->currency_id,
            'currency_code' => $product->currency?->code ?? $this->defaultCurrencyCode($request),
            'currency_symbol' => $product->currency?->symbol,
            'price_range' => $this->priceRange($product),
            'variant_count' => $product->relationLoaded('variants') ? $product->variants->count() : null,
            'image_url' => $product->primaryImage()?->path
                ? asset('storage/'.$product->primaryImage()->path)
                : null,
            'created_at' => $product->created_at?->toISOString(),
            'updated_at' => $product->updated_at?->toISOString(),
        ];
    }

    /**
     * Variant price span for the list/summary screens, or null when the
     * product has no variants.
     *
     * @return array{min: float, max: float}|null
     */
    protected function priceRange(Product $product): ?array
    {
        if (! $product->relationLoaded('variants') || $product->variants->isEmpty()) {
            return null;
        }

        $amounts = $product->variants->pluck('amount')->map(fn ($amount) => (float) $amount);

        return ['min' => (float) $amounts->min(), 'max' => (float) $amounts->max()];
    }

    /**
     * Good/Medium/Low band for the stock bar the legacy detail screen showed.
     */
    protected function stockLevel(Product $product): string
    {
        if ($product->is_digital) {
            return 'digital';
        }

        $percentage = $product->stockPercentage();

        if ($percentage >= 60) {
            return 'good';
        }

        return $percentage >= 25 ? 'medium' : 'low';
    }

    /**
     * Platform default currency, then the business's own, then NGN — the
     * fallback order the payload always used. Callers normally hand in the
     * memoized repository closure; this path only runs when they do not.
     */
    protected function defaultCurrencyCode(Request $request): string
    {
        if ($this->defaultCurrency !== null) {
            return ($this->defaultCurrency)();
        }

        return Currency::where('is_default', true)->value('code')
            ?: $request->user()?->business?->currency
            ?: 'NGN';
    }
}
