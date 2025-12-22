<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Vendor;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class VendorProductsController extends Controller
{
    private function resolveVendor(Request $request, Vendor $routeVendor): ?Vendor
    {
        $vendor = $request->user('vendor');
        if (!$vendor || $vendor->id !== $routeVendor->id) {
            return null;
        }
        return $vendor;
    }

    private function vendorStoreIds(Vendor $vendor): array
    {
        return $vendor->stores()->pluck('id')->all();
    }

    public function index(Request $request, Vendor $routeVendor): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        Log::info('vendor.products.viewed', ['vendor_id' => $vendor->id]);

        $status = strtolower((string)$request->query('status', ''));
        $q = trim((string)$request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');

        $storeIds = $this->vendorStoreIds($vendor);

        $query = Product::query()
            ->whereIn('store_id', $storeIds)
            ->with(['category', 'store', 'images'])
            ->withMin('variants', 'amount')
            ->withMax('variants', 'amount')
            ->addSelect([
                'variants_min_currency_id' => DB::table('product_variants')
                    ->select('currency_id')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->orderBy('amount', 'asc')
                    ->limit(1),
                'variants_max_currency_id' => DB::table('product_variants')
                    ->select('currency_id')
                    ->whereColumn('product_variants.product_id', 'products.id')
                    ->orderBy('amount', 'desc')
                    ->limit(1),
            ]);

        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('status', $status);
        }

        if ($q !== '') {
            $query->where(function ($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                    ->orWhere('product_code', 'like', "%$q%")
                    ->orWhereHas('category', function ($c) use ($q) {
                        $c->where('name', 'like', "%$q%");
                    });
            });
        }

        if ($from || $to) {
            $start = $from ? date('Y-m-d 00:00:00', strtotime($from)) : null;
            $end = $to ? date('Y-m-d 23:59:59', strtotime($to)) : null;
            if ($start && $end) {
                $query->whereBetween('created_at', [$start, $end]);
            } elseif ($start) {
                $query->where('created_at', '>=', $start);
            } elseif ($end) {
                $query->where('created_at', '<=', $end);
            }
        }

        $perPage = (int) $request->query('per_page', 10);
        if (!in_array($perPage, [10, 50, 100], true)) {
            $perPage = 10;
        }

        $products = $query->latest()->paginate($perPage)->withQueryString();

        $productImages = [];
        foreach ($products as $prod) {
            $pi = $prod->primaryImage();
            if ($pi && $pi->path) {
                $productImages[$prod->id] = asset('storage/' . $pi->path);
            }
        }

        $currencies = Currency::query()->get(['id', 'code', 'symbol'])->keyBy('id');

        $displayPrices = [];
        foreach ($products as $prod) {
            if ($prod->has_variants) {
                $min = $prod->variants_min_amount;
                $max = $prod->variants_max_amount;
                if ($min === null) {
                    $displayPrices[$prod->id] = '—';
                    continue;
                }
                $minCur = ($prod->variants_min_currency_id ?? null) ? ($currencies[$prod->variants_min_currency_id] ?? null) : null;
                $maxCur = ($prod->variants_max_currency_id ?? null) ? ($currencies[$prod->variants_max_currency_id] ?? null) : null;
                $minStr = ($minCur->symbol ?? '') . number_format((float) $min, 2);
                if ($max === null || $min == $max) {
                    $displayPrices[$prod->id] = $minStr;
                } else {
                    $maxStr = ($maxCur->symbol ?? '') . number_format((float) $max, 2);
                    $displayPrices[$prod->id] = $minStr . ' - ' . $maxStr;
                }
            } else {
                $cur = $currencies[$prod->currency_id ?? 0] ?? null;
                $amt = (float) ($prod->amount ?? 0);
                $sym = $cur->symbol ?? '';
                if (!is_null($prod->discount_percentage) && (float)$prod->discount_percentage > 0) {
                    $disc = $amt * (1 - ((float)$prod->discount_percentage / 100));
                    $pct = rtrim(rtrim(number_format((float)$prod->discount_percentage, 2, '.', ''), '0'), '.');
                    $displayPrices[$prod->id] = $sym . number_format($amt, 2) . ' -> ' . $sym . number_format($disc, 2) . ' (-' . $pct . '%)';
                } else {
                    $displayPrices[$prod->id] = $sym . number_format($amt, 2);
                }
            }
        }

        return view('vendors.products.index', [
            'vendor' => $vendor,
            'products' => $products,
            'status' => $status,
            'q' => $q,
            'from' => $from,
            'to' => $to,
            'perPage' => $perPage,
            'productImages' => $productImages,
            'displayPrices' => $displayPrices,
            'stores' => $vendor->stores()->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request, Vendor $routeVendor): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        $stores = $vendor->stores()->orderBy('name')->get();
        $sizeUnits = DB::table('size_units')->orderBy('name')->get();
        $weightUnits = DB::table('weight_units')->orderBy('name')->get();
        $currencies = Currency::orderBy('name')->get();
        $defaultCurrencyId = Currency::where('is_default', true)->value('id');

        $storeIds = $this->vendorStoreIds($vendor);


        $categories = Category::whereIn('store_id', $storeIds)->orderBy('name')->get();

        return view('vendors.products.create', compact('vendor', 'stores', 'categories', 'sizeUnits', 'weightUnits', 'currencies', 'defaultCurrencyId'));
    }

    public function store(ProductRequest $request, Vendor $routeVendor): RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        $store = $vendor->stores()->where('id', $request->input('store_id'))->first();
        if (!$store) {
            return back()->with('error', 'Invalid store selected.');
        }

        $data = $request->validated();
        $data['store_id'] = $store->id;

        try {
            $product = DB::transaction(function () use ($request, $data) {
                $data['cod_available'] = $request->boolean('cod_available');
                $data['featured'] = $request->boolean('featured');
                $data['has_variants'] = $request->boolean('has_variants');
                $product = Product::create($data);

                $product->cod_available = $request->boolean('cod_available');
                $product->featured = $request->boolean('featured');
                $product->has_variants = $request->boolean('has_variants');
                $product->save();

                if ($request->hasFile('images')) {
                    $pos = 0;
                    foreach ($request->file('images') as $idx => $file) {
                        $path = $file->store('products/images', 'public');
                        ProductImage::create([
                            'product_id' => $product->id,
                            'path' => $path,
                            'is_primary' => (int)$request->input('primary_image') === $idx,
                            'position' => $pos++,
                        ]);
                    }
                    if (!$product->images()->where('is_primary', true)->exists()) {
                        $first = $product->images()->orderBy('position')->first();
                        if ($first) { $first->update(['is_primary' => true]); }
                    }
                }

                if ($product->has_variants) {
                    $variants = (array)$request->input('variants', []);
                    foreach ($variants as $v) {
                        ProductVariant::create([
                            'product_id' => $product->id,
                            'sku' => $v['sku'] ?? null,
                            'size' => $v['size'] ?? null,
                            'size_unit_id' => $v['size_unit_id'] ?? null,
                            'weight' => $v['weight'] ?? null,
                            'weight_unit_id' => $v['weight_unit_id'] ?? null,
                            'color' => $v['color'] ?? null,
                            'quantity' => $v['quantity'],
                            'amount' => $v['amount'],
                            'currency_id' => $v['currency_id'] ?? null,
                            'status' => $v['status'] ?? 'active',
                            'featured' => !empty($v['featured']),
                        ]);
                    }
                }

                return $product;
            });

            Log::info('vendor.product.created', ['vendor_id' => $vendor->id, 'product_id' => $product->id]);
            ActivityLog::create([
                'user_id' => null,
                'action' => 'vendor_create_product',
                'description' => 'Vendor created a product',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string)$request->userAgent(), 0, 255),
                'metadata' => [
                    'vendor_id' => $vendor->id,
                    'product_id' => $product->id,
                    'has_variants' => (bool)$product->has_variants,
                    'store_id' => $product->store_id,
                ],
            ]);

            return redirect()->route('vendor.products.index', ['vendor' => $vendor])->with('success', 'Product created successfully.');
        } catch (QueryException $e) {
            Log::error('vendor.product.create_failed', ['error' => $e->getMessage()]);
            return back()->withInput()->with('error', 'Unable to create product. Please try again.');
        }
    }

    public function edit(Request $request, Vendor $routeVendor, Product $product): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->ownsProduct($product, $vendor)) {
            return redirect()->route('vendor.auth.login');
        }

        $stores = $vendor->stores()->orderBy('name')->get();
        $categories = Category::orderBy('name')->get();
        $sizeUnits = DB::table('size_units')->orderBy('name')->get();
        $weightUnits = DB::table('weight_units')->orderBy('name')->get();
        $currencies = Currency::orderBy('name')->get();
        $defaultCurrencyId = Currency::where('is_default', true)->value('id');
        $product->load(['images', 'variants']);

        $storeIds = $this->vendorStoreIds($vendor);
        $categories = Category::whereIn('store_id', $storeIds)->orderBy('name')->get();

        return view('vendors.products.edit', [
            'vendor' => $vendor,
            'product' => $product,
            'stores' => $stores,
            'categories' => $categories,
            'sizeUnits' => $sizeUnits,
            'weightUnits' => $weightUnits,
            'currencies' => $currencies,
            'defaultCurrencyId' => $defaultCurrencyId,
            'backUrl' => route('vendor.products.index', ['vendor' => $vendor]),
        ]);
    }

    public function update(ProductRequest $request, Vendor $routeVendor, Product $product): RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->ownsProduct($product, $vendor)) {
            return redirect()->route('vendor.auth.login');
        }

        $stores = $vendor->stores()->pluck('id')->all();
        if (!in_array($request->input('store_id'), $stores, true)) {
            return back()->with('error', 'Invalid store selection.')->withInput();
        }

        $data = $request->validated();
        try {
            DB::transaction(function () use ($request, $product, $data) {
                $data['cod_available'] = $request->boolean('cod_available');
                $data['featured'] = $request->boolean('featured');
                $data['has_variants'] = $request->boolean('has_variants');
                $product->update($data);

                if ($request->filled('delete_image_ids')) {
                    $ids = $request->input('delete_image_ids');
                    $toDelete = $product->images()->whereIn('id', $ids)->get();
                    foreach ($toDelete as $img) {
                        try { Storage::disk('public')->delete($img->path); } catch (\Throwable $e) {}
                        $img->delete();
                    }
                }

                if ($request->hasFile('images')) {
                    $pos = (int)$product->images()->max('position');
                    $pos = $pos < 0 ? 0 : $pos + 1;
                    foreach ($request->file('images') as $file) {
                        $path = $file->store('products/images', 'public');
                        ProductImage::create([
                            'product_id' => $product->id,
                            'path' => $path,
                            'is_primary' => false,
                            'position' => $pos++,
                        ]);
                    }
                }

                if ($request->filled('primary_image_id')) {
                    $pid = (int)$request->input('primary_image_id');
                    $product->images()->update(['is_primary' => false]);
                    $product->images()->where('id', $pid)->update(['is_primary' => true]);
                }

                if ($product->has_variants) {
                    $incoming = collect((array)$request->input('variants', []));
                    $keepIds = [];
                    foreach ($incoming as $v) {
                        if (!empty($v['id'])) {
                            $pv = ProductVariant::where('id', $v['id'])->where('product_id', $product->id)->first();
                            if ($pv) {
                                $pv->update([
                                    'sku' => $v['sku'] ?? $pv->sku,
                                    'size' => $v['size'] ?? null,
                                    'size_unit_id' => $v['size_unit_id'] ?? null,
                                    'weight' => $v['weight'] ?? null,
                                    'weight_unit_id' => $v['weight_unit_id'] ?? null,
                                    'color' => $v['color'] ?? null,
                                    'quantity' => $v['quantity'],
                                    'amount' => $v['amount'],
                                    'currency_id' => $v['currency_id'] ?? null,
                                    'status' => $v['status'] ?? $pv->status,
                                    'featured' => !empty($v['featured']),
                                ]);
                                $keepIds[] = $pv->id;
                            }
                        } else {
                            $pv = ProductVariant::create([
                                'product_id' => $product->id,
                                'sku' => $v['sku'] ?? null,
                                'size' => $v['size'] ?? null,
                                'size_unit_id' => $v['size_unit_id'] ?? null,
                                'weight' => $v['weight'] ?? null,
                                'weight_unit_id' => $v['weight_unit_id'] ?? null,
                                'color' => $v['color'] ?? null,
                                'quantity' => $v['quantity'],
                                'amount' => $v['amount'],
                                'currency_id' => $v['currency_id'] ?? null,
                                'status' => $v['status'] ?? 'active',
                                'featured' => !empty($v['featured']),
                            ]);
                            $keepIds[] = $pv->id;
                        }
                    }
                    if (!empty($keepIds)) {
                        ProductVariant::where('product_id', $product->id)->whereNotIn('id', $keepIds)->delete();
                    }
                } else {
                    ProductVariant::where('product_id', $product->id)->delete();
                }
            });

            ActivityLog::create([
                'user_id' => $vendor->id,
                'action' => 'vendor_update_product',
                'description' => 'Vendor updated a product',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string)$request->userAgent(), 0, 255),
                'metadata' => ['product_id' => $product->id, 'has_variants' => (bool)$product->has_variants],
            ]);

            return redirect()->route('vendor.products.index', ['vendor' => $vendor])->with('success', 'Product updated.');
        } catch (\Throwable $e) {
            Log::error('vendor.product.update_failed', ['error' => $e->getMessage(), 'product_id' => $product->id]);
            return back()->with('error', 'Unable to update product.')->withInput();
        }
    }

    public function show(Request $request, Vendor $routeVendor, Product $product): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->ownsProduct($product, $vendor)) {
            return redirect()->route('vendor.auth.login');
        }

        $product->load(['images', 'store', 'category', 'variants']);
        $priceInfo = null;
        $priceInfoSymbol = null;
        $currencySymbols = Currency::query()->get(['id', 'symbol'])->keyBy('id');
        if ($product->has_variants && $product->variants->count() > 0) {
            $minVar = $product->variants->sortBy('amount')->first();
            $maxVar = $product->variants->sortByDesc('amount')->first();
            if ($minVar && $maxVar) {
                $min = (float)$minVar->amount;
                $max = (float)$maxVar->amount;
                $priceInfo = $min == $max ? number_format($min, 2) : number_format($min, 2) . ' - ' . number_format($max, 2);
                $minSym = optional($currencySymbols[$minVar->currency_id] ?? null)->symbol ?? '';
                $maxSym = optional($currencySymbols[$maxVar->currency_id] ?? null)->symbol ?? '';
                $priceInfoSymbol = $min == $max ? ($minSym . number_format($min, 2)) : ($minSym . number_format($min, 2) . ' - ' . $maxSym . number_format($max, 2));
            }
        }

        $backUrl = route('vendor.products.index', ['vendor' => $vendor]);
        return view('vendors.products.show', compact('vendor', 'product', 'priceInfo', 'priceInfoSymbol', 'backUrl', 'currencySymbols'));
    }

    public function updateStatus(Request $request, Vendor $routeVendor, Product $product): RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->ownsProduct($product, $vendor)) {
            return redirect()->route('vendor.auth.login');
        }

        $data = $request->validate(['status' => 'required|in:active,inactive']);
        $product->update(['status' => $data['status']]);
        $message = $data['status'] === 'active' ? 'Product activated' : 'Product deactivated';
        return redirect()->route('vendor.products.index', ['vendor' => $vendor])->with('success', $message);
    }

    public function destroy(Request $request, Vendor $routeVendor, Product $product): RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->ownsProduct($product, $vendor)) {
            return redirect()->route('vendor.auth.login');
        }

        foreach ($product->images as $img) {
            try { Storage::disk('public')->delete($img->path); } catch (\Throwable $e) {}
        }
        $product->delete();
        return redirect()->route('vendor.products.index', ['vendor' => $vendor])->with('success', 'Product deleted.');
    }

    private function ownsProduct(Product $product, Vendor $vendor): bool
    {
        return in_array((int)$product->store_id, $this->vendorStoreIds($vendor), true);
    }
}
