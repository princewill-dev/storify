<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use App\Models\ActivityLog;
use App\Models\PageStyling;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function indexByStore(Request $request, string $store_slug): View
    {
        // Find store by slug and ensure it's not deleted
        $store = Store::where('slug', $store_slug)
            ->whereNotIn('status', ['deleted'])
            ->firstOrFail();

        // Check if store is pending - show pending page
        if ($store->status === 'pending') {
            $services = collect();
            try {
                $services = \DB::table('services')
                    ->where('status', 'active')
                    ->orderBy('position')
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get(['title', 'page_link']);
            } catch (\Throwable $e) {
                \Log::warning('services_fetch_failed', ['error' => $e->getMessage()]);
            }

            return view('home.pages.management.store-pending', compact('store', 'services'));
        }

        // Filters
        $q = trim((string) $request->query('q', ''));
        $status = $request->query('status');

        $productsQuery = Product::query()
            ->with(['images','variants','category','store'])
            ->where('store_id', $store->id);

        if ($q !== '') {
            $productsQuery->where(function($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                  ->orWhere('slug', 'like', "%$q%")
                  ->orWhere('product_code', 'like', "%$q%");
            });
        }
        if (in_array(strtolower((string)$status), ['active','inactive','deleted'], true)) {
            $productsQuery->where('status', strtolower($status));
        } else {
            $productsQuery->where('status', '!=', 'deleted');
        }

        $products = $productsQuery->latest()->paginate(24)->withQueryString();

        // Currency symbols map
        $currencySymbols = [];
        try {
            foreach (\DB::table('currencies')->select('id','symbol')->get() as $r) { $currencySymbols[$r->id] = $r->symbol; }
        } catch (\Throwable $e) {}
        $fallbackSym = '';
        try { $fallbackSym = (string)(\View::shared('company')->currency_symbol ?? ''); } catch (\Throwable $e) {}

        // Compute display prices for listing (handle variants and discounts)
        $products->getCollection()->transform(function($p) use ($currencySymbols, $fallbackSym) {
            if ($p->has_variants && $p->variants && $p->variants->count() > 0) {
                $minVar = $p->variants->sortBy('amount')->first();
                $sym = $currencySymbols[$minVar->currency_id ?? 0] ?? $fallbackSym;
                $p->display_price = $sym . number_format((float)$minVar->amount, 2);
                $p->display_price_was = null;
                $p->price_amount_numeric = (float)$minVar->amount;
                $p->price_currency_symbol = $sym;
            } else {
                $sym = $currencySymbols[$p->currency_id ?? 0] ?? $fallbackSym;
                $amt = (float)($p->amount ?? 0);
                $discPct = (float)($p->discount_percentage ?? 0);
                if ($discPct > 0) {
                    $disc = $amt * (1 - ($discPct/100));
                    $p->display_price = $sym . number_format($disc, 2);
                    $p->display_price_was = $sym . number_format($amt, 2);
                    $p->price_amount_numeric = $disc;
                    $p->price_currency_symbol = $sym;
                } else {
                    $p->display_price = $sym . number_format($amt, 2);
                    $p->display_price_was = null;
                    $p->price_amount_numeric = $amt;
                    $p->price_currency_symbol = $sym;
                }
            }
            // Prepare lightweight modal payload helpers
            $p->modal_discount_pct = $p->has_variants ? null : (float)($p->discount_percentage ?? 0);
            $p->modal_qty_max = (int)($p->quantity ?? 0);
            $p->modal_sku = $p->product_code;
            $p->modal_category = $p->category->name ?? '';
            $p->modal_tags = collect(explode(',', (string)($p->tags ?? '')))->map(fn($t)=>trim($t))->filter()->values()->all();
            $p->modal_images = $p->images->map(fn($img)=> asset('storage/'.$img->path))->values()->all();
            return $p;
        });

        // App logs (truncate sensitive)
        \Log::info('store_products_viewed', [
            'store_id' => $store->id,
            'store_slug' => substr($store->slug, 0, 32),
            'user_id' => auth()->id(),
            'q' => $q !== '' ? substr($q, 0, 32) : null,
            'count' => $products->count(),
        ]);

        // Activity logs
        try {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'view_store_products',
                'description' => 'Viewed store products',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string)$request->userAgent(), 0, 255),
                'metadata' => [
                    'store_id' => $store->id,
                    'store_slug' => $store->slug,
                    'q' => $q,
                    'status' => $status,
                ],
            ]);
        } catch (\Throwable $e) {
            \Log::warning('activity_log_failed', ['context' => 'view_store_products', 'error' => $e->getMessage()]);
        }

        return view('home.pages.products.store_products', compact('store','products','q','status'));
    }
    public function show(Request $request, string $store_slug, string $slug, string $code)
    {
        $query = Product::with(['images','store','variants']);
        $product = $query->where('product_code', $code)->first();

        if (!$product) {
            \Log::warning('Product details: code lookup failed, trying slug', [
                'requested_slug' => $slug,
                'requested_code' => $code,
            ]);
            $product = $query->where('slug', $slug)->firstOrFail();
        }

        if ($product->slug !== $slug || $product->product_code !== $code || ($product->store && $product->store->slug !== $store_slug)) {
            return redirect()->route('home.products.show', [
                'store_slug' => $product->store?->slug ?? $store_slug,
                'slug' => $product->slug,
                'code' => $product->product_code,
            ]);
        }

        // Increment views counter (atomic)
        try {
            $product->increment('views');
        } catch (\Throwable $e) {
            \Log::warning('product_views_increment_failed', ['product_id' => $product->id, 'error' => $e->getMessage()]);
        }

        try {
            $companyShared = \View::shared('company');
            $currency = $companyShared->currency_symbol ?? '';
        } catch (\Throwable $e) {
            $currency = '';
        }
        // Build currency symbols map
        $currencySymbols = [];
        try {
            $currencyRows = \DB::table('currencies')->select('id','symbol')->get();
            foreach ($currencyRows as $row) { $currencySymbols[$row->id] = $row->symbol; }
        } catch (\Throwable $e) {}

        // Unit code maps (used for variant and non-variant displays)
        $sizeUnitCodes = [];
        $weightUnitCodes = [];
        try {
            foreach (\DB::table('size_units')->select('id','code')->get() as $r) { $sizeUnitCodes[$r->id] = $r->code; }
            foreach (\DB::table('weight_units')->select('id','code')->get() as $r) { $weightUnitCodes[$r->id] = $r->code; }
        } catch (\Throwable $e) {}

        // Build base amount display (keep numeric on model)
        $symbolForProduct = $currencySymbols[$product->currency_id ?? 0] ?? $currency;
        $baseAmount = (float)($product->amount ?? 0);
        $hasDiscount = !is_null($product->discount_percentage) && (float)$product->discount_percentage > 0;
        $discountedAmount = $hasDiscount ? ($baseAmount * (1 - ((float)$product->discount_percentage/100))) : null;
        $displayBaseAmount = $symbolForProduct . number_format($baseAmount, 2);
        $displayDiscountedAmount = $hasDiscount ? ($symbolForProduct . number_format((float)$discountedAmount, 2)) : null;
        $displayDiscountPct = $hasDiscount ? rtrim(rtrim(number_format((float)$product->discount_percentage, 2, '.', ''), '0'), '.') : null;

        // If product has variants, prepare display data (attributes matrix)
        $priceInfoSymbol = null;
        $sizeOptions = [];
        $weightOptions = [];
        $colorOptions = [];
        $variantMatrix = [];
        $defaultSelection = ['size' => null, 'weight' => null, 'color' => null];
        $baseMeta = [
            'qty' => (int)($product->quantity ?? 0),
            'size' => null,
            'weight' => null,
            'color' => $product->color ?? null,
        ];
        if ($product->has_variants && $product->variants) {
            $sortedMin = $product->variants->sortBy('amount')->first();
            $sortedMax = $product->variants->sortByDesc('amount')->first();
            if ($sortedMin && $sortedMax) {
                $minSym = $currencySymbols[$sortedMin->currency_id ?? 0] ?? '';
                $maxSym = $currencySymbols[$sortedMax->currency_id ?? 0] ?? '';
                $min = (float) $sortedMin->amount;
                $max = (float) $sortedMax->amount;
                $priceInfoSymbol = $min == $max ? ($minSym . number_format($min, 2)) : ($minSym . number_format($min, 2) . ' - ' . $maxSym . number_format($max, 2));
                // Default selection to the cheapest variant
                $defaultSelection = [
                    'size' => is_null($sortedMin->size) ? null : (string)$sortedMin->size,
                    'weight' => is_null($sortedMin->weight) ? null : (string)$sortedMin->weight,
                    'color' => $sortedMin->color ?: null,
                ];
            }
            // Build attribute options and matrix
            foreach ($product->variants as $v) {
                $sizeKey = is_null($v->size) ? null : (string) $v->size;
                $weightKey = is_null($v->weight) ? null : (string) $v->weight;
                $colorKey = empty($v->color) ? null : (string) $v->color;

                if (!is_null($sizeKey)) {
                    $sizeOptions[$sizeKey] = rtrim(rtrim(number_format((float)$v->size, 2, '.', ''), '0'), '.')
                        . (isset($sizeUnitCodes[$v->size_unit_id]) ? (' ' . $sizeUnitCodes[$v->size_unit_id]) : '');
                }
                if (!is_null($weightKey)) {
                    $weightOptions[$weightKey] = rtrim(rtrim(number_format((float)$v->weight, 2, '.', ''), '0'), '.')
                        . (isset($weightUnitCodes[$v->weight_unit_id]) ? (' ' . $weightUnitCodes[$v->weight_unit_id]) : '');
                }
                if (!is_null($colorKey)) {
                    $colorOptions[$colorKey] = $colorKey;
                }

                $sym = $currencySymbols[$v->currency_id ?? 0] ?? '';
                $price = $sym . number_format((float)($v->amount ?? 0), 2);
                $key = ($sizeKey ?? '') . '|' . ($weightKey ?? '') . '|' . ($colorKey ?? '');
                $variantMatrix[$key] = [
                    'id' => $v->id,
                    'price' => $price,
                    'qty' => (int)($v->quantity ?? 0),
                ];
            }
            ksort($sizeOptions); ksort($weightOptions); ksort($colorOptions);
        } else {
            // Prepare non-variant display meta
            if (!is_null($product->size)) {
                $baseMeta['size'] = rtrim(rtrim(number_format((float)$product->size, 2, '.', ''), '0'), '.')
                    . (isset($sizeUnitCodes[$product->size_unit_id]) ? (' ' . $sizeUnitCodes[$product->size_unit_id]) : '');
            }
            if (!is_null($product->weight)) {
                $baseMeta['weight'] = rtrim(rtrim(number_format((float)$product->weight, 2, '.', ''), '0'), '.')
                    . (isset($weightUnitCodes[$product->weight_unit_id]) ? (' ' . $weightUnitCodes[$product->weight_unit_id]) : '');
            }
        }

        // Delivery routes (active only), grouped by state
        $states = [];
        $areasByState = [];
        try {
            $routes = \App\Models\DeliveryRoute::where('active', true)
                ->orderBy('state')
                ->orderBy('area')
                ->get(['id','state','area','fee','delivery_days']);
            foreach ($routes as $r) {
                if (!isset($areasByState[$r->state])) { $areasByState[$r->state] = []; $states[] = $r->state; }
                $areasByState[$r->state][] = [
                    'id' => $r->id,
                    'area' => $r->area,
                    'fee' => $r->fee,
                    'days' => $r->delivery_days,
                ];
            }
        } catch (\Throwable $e) {}

        // VAT
        $vatPercentage = 0.0;
        try { $vatPercentage = (float) (\App\Models\Vat::current()?->percentage ?? 0); } catch (\Throwable $e) {}

        $gallery = $product->images ?? collect();
        $placeholder = asset('home/images/no-image.jpg');
        $galleryItems = $gallery->map(function($img) {
            $src = asset('storage/' . $img->path);
            return ['full' => $src, 'thumb' => $src];
        });
        $tagsArr = collect(explode(',', (string)($product->tags ?? '')))
            ->map(fn($t) => trim($t))
            ->filter(fn($t) => $t !== '')
            ->values();

        // Get page styling
        $pageStyling = PageStyling::getPageStyling('product_details');

        return view('home.pages.products.details', compact(
            'product',
            'gallery',
            'placeholder',
            'galleryItems',
            'tagsArr',
            'priceInfoSymbol',
            'sizeOptions',
            'weightOptions',
            'colorOptions',
            'variantMatrix',
            'defaultSelection',
            'baseMeta',
            'displayBaseAmount',
            'displayDiscountedAmount',
            'hasDiscount',
            'displayDiscountPct',
            'states',
            'areasByState',
            'symbolForProduct',
            'baseAmount',
            'discountedAmount',
            'vatPercentage',
            'pageStyling'
        ));
    }
}
