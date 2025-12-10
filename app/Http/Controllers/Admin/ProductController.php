<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Store;
use App\Models\Category;
use App\Models\Vendor;
use App\Models\User;
use App\Models\Currency;
use App\Models\ProductVariant;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Http\Requests\Admin\ProductRequest;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request, ?Store $store = null)
    {
        Log::info('products_viewed', ['user_id' => auth()->id()]);
        $status = $request->query('status');
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');
        $storeFilter = $request->query('store_id');

        $query = Product::query()
            ->with(['category','store','images'])
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
        if (in_array(strtolower((string)$status), ['active','inactive'], true)) {
            $query->where('status', strtolower($status));
        }
        if ($q !== '') {
            $query->where(function($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                  ->orWhere('product_code', 'like', "%$q%")
                  ->orWhereHas('store', function($s) use ($q) { $s->where('name', 'like', "%$q%"); })
                  ->orWhereHas('category', function($c) use ($q) { $c->where('name', 'like', "%$q%"); });
            });
        }
        if ($store) {
            $query->where('store_id', $store->id);
        } elseif ($storeFilter) {
            $sf = Store::where('store_id', $storeFilter)->orWhere('id', $storeFilter)->first();
            if ($sf) { $query->where('store_id', $sf->id); }
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

        // Resolve product primary image URLs (no fallbacks)
        $productImages = [];
        foreach ($products as $prod) {
            $pi = $prod->primaryImage();
            if ($pi && $pi->path) {
                $productImages[$prod->id] = asset('storage/' . $pi->path);
            }
        }

        // Currency map for fast lookup in the view
        $currencies = Currency::query()->get(['id','code','symbol'])->keyBy('id');

        // Build display prices (with currency symbol only) for table rendering
        $displayPrices = [];
        foreach ($products as $prod) {
            if ($prod->has_variants) {
                $min = $prod->variants_min_amount;
                $max = $prod->variants_max_amount;
                if ($min === null) { $displayPrices[$prod->id] = '—'; continue; }
                $minCur = ($prod->variants_min_currency_id ?? null) ? ($currencies[$prod->variants_min_currency_id] ?? null) : null;
                $maxCur = ($prod->variants_max_currency_id ?? null) ? ($currencies[$prod->variants_max_currency_id] ?? null) : null;
                $minStr = ($minCur->symbol ?? '') . number_format((float)$min, 2);
                if ($max === null || $min == $max) {
                    $displayPrices[$prod->id] = $minStr;
                } else {
                    $maxStr = ($maxCur->symbol ?? '') . number_format((float)$max, 2);
                    $displayPrices[$prod->id] = $minStr . ' - ' . $maxStr;
                }
            } else {
                $cur = $currencies[$prod->currency_id ?? 0] ?? null;
                $amt = (float)($prod->amount ?? 0);
                $sym = $cur->symbol ?? '';
                if (!is_null($prod->discount_percentage) && (float)$prod->discount_percentage > 0) {
                    $disc = $amt * (1 - ((float)$prod->discount_percentage/100));
                    $pct = rtrim(rtrim(number_format((float)$prod->discount_percentage, 2, '.', ''), '0'), '.');
                    $displayPrices[$prod->id] = $sym . number_format($amt, 2) . ' -> ' . $sym . number_format($disc, 2) . ' (-' . $pct . '%)';
                } else {
                    $displayPrices[$prod->id] = $sym . number_format($amt, 2);
                }
            }
        }

        return view('admin.products.index', [
            'products' => $products,
            'status' => $status,
            'q' => $q,
            'from' => $from,
            'to' => $to,
            'store' => $store,
            'perPage' => $perPage,
            'productImages' => $productImages,
            'displayPrices' => $displayPrices,
        ]);
    }

    public function create(Request $request, ?Store $store = null)
    {
        Log::info('product_create_viewed', ['user_id' => auth()->id()]);
        $allow = (int) env('ALLOW_MS_SETUP', 0) === 1;
        if (!$allow) {
            $superadmin = User::where('role', 'superadmin')->orderBy('id')->first();
            if ($superadmin) {
                $saVendor = Vendor::where('email', $superadmin->email)->first();
                if ($saVendor) {
                    $stores = Store::where('vendor_id', $saVendor->id)->orderBy('name')->get();
                } else {
                    $stores = Store::orderBy('name')->get();
                }
            } else {
                $stores = Store::orderBy('name')->get();
            }
        } else {
            $stores = Store::orderBy('name')->get();
        }
        $categories = Category::orderBy('name')->get();
        $sizeUnits = \DB::table('size_units')->orderBy('name')->get();
        $weightUnits = \DB::table('weight_units')->orderBy('name')->get();
        $currencies = Currency::orderBy('name')->get();
        $defaultCurrencyId = Currency::where('is_default', true)->value('id');
        $selectedStoreId = null;
        if ($store) {
            $selectedStoreId = $store->id;
        } else {
            $q = $request->query('store_id');
            if ($q) {
                $qs = Store::where('store_id', $q)->orWhere('id', $q)->first();
                if ($qs) { $selectedStoreId = $qs->id; }
            }
        }
        if (!$allow && !$selectedStoreId && isset($stores) && $stores->count() === 1) {
            $selectedStoreId = $stores->first()->id;
        }
        return view('admin.products.create', compact('stores','categories','sizeUnits','weightUnits','selectedStoreId','currencies','defaultCurrencyId'));
    }

    public function store(ProductRequest $request)
    {
        Log::info('product_create_requested', ['user_id' => auth()->id(), 'ip' => $request->ip()]);
        $data = $request->validated();

        try {
            $product = DB::transaction(function () use ($request, $data) {
                $product = new Product();
                $allow = (int) env('ALLOW_MS_SETUP', 0) === 1;
                if (!$allow) {
                    $superadmin = User::where('role', 'superadmin')->orderBy('id')->first();
                    if ($superadmin) {
                        $saVendor = Vendor::where('email', $superadmin->email)->first();
                        if ($saVendor) {
                            $storeIds = Store::where('vendor_id', $saVendor->id)->pluck('id')->all();
                            if (!in_array($data['store_id'], $storeIds, true)) {
                                $data['store_id'] = $storeIds[0] ?? $data['store_id'];
                            }
                        }
                    }
                }
                $product->fill($data);
                $product->cod_available = $request->boolean('cod_available');
                $product->featured = $request->boolean('featured');
                $product->has_variants = $request->boolean('has_variants');
                
                // Bulk fields
                if ($request->filled('bulk_quantity')) {
                    $product->bulk_quantity = (int)$request->input('bulk_quantity');
                }
                if ($request->filled('bulk_price')) {
                    $product->bulk_price = (float)$request->input('bulk_price');
                }

                $product->slug = null;
                $product->product_code = null;
                $product->save();

                Log::info('product_created_debug', [
                    'product_id' => $product->id,
                    'bulk_quantity' => $product->bulk_quantity,
                    'bulk_price' => $product->bulk_price,
                    'raw_input' => $request->only(['bulk_quantity', 'bulk_price'])
                ]);

                // Handle images
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

                // Handle variants
                if ($product->has_variants) {
                    $variants = (array) $request->input('variants', []);
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

            Log::info('product_created', ['user_id' => auth()->id(), 'product_id' => $product->id]);
            try {
                ActivityLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'create_product',
                    'description' => 'Product created',
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string)$request->userAgent(), 0, 255),
                    'metadata' => [
                        'product_id' => $product->id,
                        'has_variants' => (bool)$product->has_variants,
                        'store_id' => $product->store_id,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('activity_log_failed', ['context' => 'create_product', 'error' => $e->getMessage()]);
            }
            return redirect()->route('admin.stores.products.index', $product->store)->with('success', 'Product created');
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('product_create_validation_failed', ['errors' => $e->errors()]);
            return back()->withErrors($e->errors())->with('error', 'Please fix the highlighted errors.')->withInput();
        } catch (\Throwable $e) {
            Log::error('product_create_failed', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return back()->with('error', 'Unable to create product. Please try again.')->withInput();
        }
    }

    public function edit(Product $product)
    {
        Log::info('product_edit_viewed', ['user_id' => auth()->id(), 'product_id' => $product->id]);
        $stores = Store::orderBy('name')->get();
        $categories = Category::orderBy('name')->get();
        $sizeUnits = \DB::table('size_units')->orderBy('name')->get();
        $weightUnits = \DB::table('weight_units')->orderBy('name')->get();
        $currencies = Currency::orderBy('name')->get();
        $defaultCurrencyId = Currency::where('is_default', true)->value('id');
        $product->load(['images','store','variants']);

        $backUrl = null;
        if ($product->store) {
            $backUrl = route('admin.stores.products.index', $product->store);
        }

        return view('admin.products.edit', compact('product','stores','categories','sizeUnits','weightUnits','backUrl','currencies','defaultCurrencyId'));
    }

    public function show(Product $product)
    {
        Log::info('product_admin_show_viewed', ['user_id' => auth()->id(), 'product_id' => $product->id]);
        $product->load(['images','store','category','variants']);
        $priceInfo = null;
        $priceInfoSymbol = null;
        $currencySymbols = Currency::query()->get(['id','symbol'])->keyBy('id');
        if ($product->has_variants && $product->variants->count() > 0) {
            $minVar = $product->variants->sortBy('amount')->first();
            $maxVar = $product->variants->sortByDesc('amount')->first();
            if ($minVar && $maxVar) {
                $min = (float) $minVar->amount;
                $max = (float) $maxVar->amount;
                $priceInfo = $min == $max ? number_format($min, 2) : number_format($min, 2) . ' - ' . number_format($max, 2);
                $minSym = optional($currencySymbols[$minVar->currency_id] ?? null)->symbol ?? '';
                $maxSym = optional($currencySymbols[$maxVar->currency_id] ?? null)->symbol ?? '';
                $priceInfoSymbol = $min == $max ? ($minSym . number_format($min, 2)) : ($minSym . number_format($min, 2) . ' - ' . $maxSym . number_format($max, 2));
            }
        }
        $backUrl = route('admin.products.index');
        return view('admin.products.show', compact('product','priceInfo','priceInfoSymbol','backUrl','currencySymbols'));
    }

    public function showInStore(Store $store, string $code)
    {
        Log::info('product_admin_store_show_viewed', ['user_id' => auth()->id(), 'store_id' => $store->id, 'code' => $code]);
        $product = Product::with(['images','store','category','variants'])
            ->where('store_id', $store->id)
            ->where('product_code', $code)
            ->firstOrFail();

        $priceInfo = null;
        $priceInfoSymbol = null;
        $currencySymbols = Currency::query()->get(['id','symbol'])->keyBy('id');
        if ($product->has_variants && $product->variants->count() > 0) {
            $minVar = $product->variants->sortBy('amount')->first();
            $maxVar = $product->variants->sortByDesc('amount')->first();
            if ($minVar && $maxVar) {
                $min = (float) $minVar->amount;
                $max = (float) $maxVar->amount;
                $priceInfo = $min == $max ? number_format($min, 2) : number_format($min, 2) . ' - ' . number_format($max, 2);
                $minSym = optional($currencySymbols[$minVar->currency_id] ?? null)->symbol ?? '';
                $maxSym = optional($currencySymbols[$maxVar->currency_id] ?? null)->symbol ?? '';
                $priceInfoSymbol = $min == $max ? ($minSym . number_format($min, 2)) : ($minSym . number_format($min, 2) . ' - ' . $maxSym . number_format($max, 2));
            }
        }

        $backUrl = route('admin.stores.products.index', $store);
        return view('admin.products.show', compact('product','priceInfo','priceInfoSymbol','backUrl','currencySymbols'));
    }

    public function update(ProductRequest $request, Product $product)
    {
        Log::info('product_update_requested', ['user_id' => auth()->id(), 'product_id' => $product->id]);
        $data = $request->validated();

        try {
            DB::transaction(function () use ($request, $product, $data) {
                $data['cod_available'] = $request->boolean('cod_available');
                $data['featured'] = $request->boolean('featured');
                $data['has_variants'] = $request->boolean('has_variants');
                
                // Bulk fields
                $data['bulk_quantity'] = $request->filled('bulk_quantity') ? (int)$request->input('bulk_quantity') : null;
                $data['bulk_price'] = $request->filled('bulk_price') ? (float)$request->input('bulk_price') : null;

                $product->update($data);

                Log::info('product_updated_debug', [
                    'product_id' => $product->id,
                    'bulk_quantity' => $product->bulk_quantity,
                    'bulk_price' => $product->bulk_price,
                    'raw_input' => $request->only(['bulk_quantity', 'bulk_price'])
                ]);

                if ($request->filled('delete_image_ids')) {
                    $ids = $request->input('delete_image_ids');
                    $toDelete = $product->images()->whereIn('id', $ids)->get();
                    foreach ($toDelete as $img) {
                        try { Storage::disk('public')->delete($img->path); } catch (\Throwable $e) {}
                        $img->delete();
                    }
                }

                if ($request->hasFile('images')) {
                    $pos = (int) $product->images()->max('position');
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
                    $pid = (int) $request->input('primary_image_id');
                    $product->images()->update(['is_primary' => false]);
                    $product->images()->where('id', $pid)->update(['is_primary' => true]);
                }

                // Sync variants
                if ($product->has_variants) {
                    $incoming = collect((array) $request->input('variants', []));
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
                    // Remove variants not present in payload
                    if (!empty($keepIds)) {
                        ProductVariant::where('product_id', $product->id)->whereNotIn('id', $keepIds)->delete();
                    }
                } else {
                    // If switching from variants to single-SKU, remove all variants
                    ProductVariant::where('product_id', $product->id)->delete();
                }
            });

            Log::info('product_updated', ['user_id' => auth()->id(), 'product_id' => $product->id]);
            try {
                ActivityLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'update_product',
                    'description' => 'Product updated',
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string)$request->userAgent(), 0, 255),
                    'metadata' => [
                        'product_id' => $product->id,
                        'has_variants' => (bool)$product->has_variants,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('activity_log_failed', ['context' => 'update_product', 'error' => $e->getMessage()]);
            }
            return redirect()->route('admin.stores.products.index', $product->store)->with('success', 'Product updated');
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('product_update_validation_failed', ['errors' => $e->errors(), 'product_id' => $product->id]);
            return back()->withErrors($e->errors())->with('error', 'Please fix the highlighted errors.')->withInput();
        } catch (\Throwable $e) {
            Log::error('product_update_failed', ['message' => $e->getMessage(), 'product_id' => $product->id, 'trace' => $e->getTraceAsString()]);
            return back()->with('error', 'Unable to update product. Please try again.')->withInput();
        }
    }

    public function destroy(Product $product)
    {
        Log::info('product_delete_requested', ['user_id' => auth()->id(), 'product_id' => $product->id]);
        $store = $product->store; // capture before delete so we can redirect to scoped list
        $perPage = request()->input('per_page');
        $page = request()->input('page');
        foreach ($product->images as $img) {
            try { Storage::disk('public')->delete($img->path); } catch (\Throwable $e) {}
        }
        $product->delete();
        $url = route('admin.stores.products.index', $store);
        $qs = [];
        if ($perPage) { $qs['per_page'] = $perPage; }
        if ($page) { $qs['page'] = $page; }
        if (!empty($qs)) { $url .= ('?' . http_build_query($qs)); }
        return redirect()->to($url)->with('success', 'Product deleted');
    }

    public function updateStatus(Request $request, Product $product)
    {
        $data = $request->validate([
            'status' => 'required|in:active,inactive',
        ]);
        $product->update(['status' => $data['status']]);
        $msg = $data['status'] === 'active' ? 'Product activated' : 'Product deactivated';
        $perPage = $request->input('per_page');
        $page = $request->input('page');
        $url = route('admin.stores.products.index', $product->store);
        $qs = [];
        if ($perPage) { $qs['per_page'] = $perPage; }
        if ($page) { $qs['page'] = $page; }
        if (!empty($qs)) { $url .= ('?' . http_build_query($qs)); }
        return redirect()->to($url)->with('success', $msg);
    }
}
