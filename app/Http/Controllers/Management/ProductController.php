<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProductController extends Controller
{


    private function userStoreIds(User $user): array
    {
        return $user->accessibleStores()->where('status', '!=', 'deleted')->pluck('id')->all();
    }

    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        Log::info('vendor.products.viewed', ['user_id' => $user->id]);

        $status = strtolower((string)$request->query('status', ''));
        $q = trim((string)$request->query('q', ''));
        $from = $request->query('from');
        $to = $request->query('to');

        $storeIds = $this->userStoreIds($user);
        $selectedPublicStoreId = $request->query('store_id');
        $selectedStoreId = null;
        $selectedStore = null;

        if ($selectedPublicStoreId) {
            $selectedStore = $user->accessibleStores()
                ->where('store_id', $selectedPublicStoreId)
                ->first();
            
            if ($selectedStore) {
                $selectedStoreId = $selectedStore->id;
            }
        }

        $query = Product::query()
            ->whereIn('store_id', $storeIds);

        if ($selectedStoreId) {
            $query->where('store_id', $selectedStoreId);
        }

        $query->with(['category', 'store', 'images', 'section.warehouse'])
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

        $perPage = (int) $request->query('per_page', 100);
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

        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Products']];

        return view('management.products.index', [
            'user' => $user,
            'products' => $products,
            'status' => $status,
            'q' => $q,
            'from' => $from,
            'to' => $to,
            'perPage' => $perPage,
            'productImages' => $productImages,
            'displayPrices' => $displayPrices,
            'stores' => $user->accessibleStores()->where('status', '!=', 'deleted')->orderBy('name')->get(),
            'breadcrumbs' => $breadcrumbs,
        ]);
    }

    public function create(Request $request, ?\App\Models\Warehouse $warehouse = null): View|RedirectResponse
    {
        $user = $request->user();

        $sizeUnits = DB::table('size_units')->orderBy('name')->get();
        $weightUnits = DB::table('weight_units')->orderBy('name')->get();
        $currencies = Currency::orderBy('name')->get();
        $defaultCurrencyId = Currency::where('is_default', true)->value('id');

        $warehouses = ($user->isStaff()
            ? $user->assignedWarehouses()
            : \App\Models\Warehouse::where('user_id', $user->id))
            ->where('status', '!=', 'deleted')->orderBy('name')->get();

        $selectedWarehouseId = $warehouse?->id ?? old('warehouse_id');

        // Load sections — scoped to the preselected warehouse, or all user sections
        $sections = \App\Models\Section::whereHas('warehouse', function ($q) use ($user, $selectedWarehouseId) {
            $q->where('user_id', $user->id);
        })->where('status', '!=', 'deleted')->orderBy('name')->get();

        $breadcrumbs = $warehouse
            ? [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Warehouses', 'url' => route('management.warehouses.index')], ['label' => $warehouse->name, 'url' => route('management.warehouses.show', $warehouse)], ['label' => 'Add Product']]
            : [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Products', 'url' => route('management.products.index')], ['label' => 'Create']];

        $backUrl = $warehouse
            ? route('management.warehouses.show', $warehouse)
            : route('management.products.index', ['user' => $user]);

        return view('management.products.create', [
            'user' => $user,
            'warehouses' => $warehouses,
            'sections' => $sections,
            'selectedWarehouseId' => $selectedWarehouseId,
            'sizeUnits' => $sizeUnits,
            'weightUnits' => $weightUnits,
            'currencies' => $currencies,
            'defaultCurrencyId' => $defaultCurrencyId,
            'backUrl' => $backUrl,
            'breadcrumbs' => $breadcrumbs,
            'preselectedWarehouse' => $warehouse,
        ]);
    }

    public function edit(Request $request, Product $product): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->ownsProduct($product, $user)) {
            return redirect()->route('management.auth.login');
        }

        $stores = $user->accessibleStores()->where('status', '!=', 'deleted')->orderBy('name')->get();
        $sizeUnits = DB::table('size_units')->orderBy('name')->get();
        $weightUnits = DB::table('weight_units')->orderBy('name')->get();
        $currencies = Currency::orderBy('name')->get();

        $storeIds = $this->userStoreIds($user);
        $categories = Category::whereIn('store_id', $storeIds)->orderBy('name')->get();

        $sections = \App\Models\Section::whereHas('warehouse', function ($q) use ($user) {
            $q->where('user_id', $user->id);
        })->where('status', '!=', 'deleted')->orderBy('name')->get();

        $warehouses = ($user->isStaff()
            ? $user->assignedWarehouses()
            : \App\Models\Warehouse::where('user_id', $user->id))
            ->where('status', '!=', 'deleted')->orderBy('name')->get();

        $product->load(['images', 'variants', 'category', 'section']);
        $backUrl = route('management.products.show', $product);

        $breadcrumbs = [
            ['label' => 'Dashboard', 'url' => route('management.dashboard')],
            ['label' => 'Products', 'url' => route('management.products.index')],
            ['label' => $product->name, 'url' => route('management.products.show', $product)],
            ['label' => 'Edit'],
        ];

        return view('management.products.edit', compact(
            'user', 'product', 'stores', 'categories', 'sections', 'warehouses',
            'sizeUnits', 'weightUnits', 'currencies', 'backUrl', 'breadcrumbs'
        ));
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user) {
            return redirect()->route('management.auth.login');
        }

        $stores = $user->accessibleStores()->where('status', '!=', 'deleted')->pluck('id')->all();
        if ($request->filled('store_id') && !in_array((int) $request->input('store_id'), $stores, true)) {
            return back()->with('error', 'Invalid store selection.')->withInput();
        }

        // Warehouse is the primary location for new products
        if (!$request->filled('warehouse_id')) {
            return back()->with('error', 'Please assign the product to a warehouse.')->withInput();
        }

        $data = $request->validated();
        $data['user_id'] = $user->id;
        $data['business_id'] = $user->business_id;
        $data['cod_available'] = $request->boolean('cod_available');
        $data['featured'] = $request->boolean('featured');
        $data['has_variants'] = $request->boolean('has_variants');

        // Auto-assign warehouse_id from section
        if (!empty($data['section_id']) && empty($data['warehouse_id'])) {
            $section = \App\Models\Section::find($data['section_id']);
            if ($section && $section->warehouse_id) {
                $data['warehouse_id'] = $section->warehouse_id;
            }
        }

        try {
            DB::transaction(function () use ($request, $data, $user) {
                $product = Product::create($data);

                if ($request->hasFile('images')) {
                    $pos = 0;
                    foreach ($request->file('images') as $file) {
                        $path = $file->store('products/images', 'public');
                        ProductImage::create([
                            'product_id' => $product->id,
                            'path' => $path,
                            'is_primary' => $pos === 0,
                            'position' => $pos++,
                        ]);
                    }
                }

                if ($product->has_variants) {
                    $incoming = collect((array) $request->input('variants', []));
                    foreach ($incoming as $v) {
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

                ActivityLog::create([
                    'user_id' => null,
                    'action' => 'vendor_create_product',
                    'description' => 'Vendor created a product: ' . $product->name,
                    'ip_address' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 255),
                    'metadata' => ['user_id' => $user->id, 'product_id' => $product->id],
                ]);
            });

            return redirect()->route('management.products.index')
                ->with('success', 'Product created successfully.');
        } catch (\Throwable $e) {
            Log::error('vendor.product.create_failed', ['error' => $e->getMessage(), 'user_id' => $user->id]);
            return back()->with('error', 'Unable to create product. Please try again.')->withInput();
        }
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->ownsProduct($product, $user)) {
            return redirect()->route('management.auth.login');
        }

        $stores = $user->accessibleStores()->where('status', '!=', 'deleted')->pluck('id')->all();
        if (!in_array((int)$request->input('store_id'), $stores, true)) {
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
                'user_id' => null,
                'action' => 'vendor_update_product',
                'description' => 'Vendor updated a product',
                'ip_address' => $request->ip(),
                'user_agent' => substr((string)$request->userAgent(), 0, 255),
                'metadata' => ['user_id' => $user->id, 'product_id' => $product->id, 'has_variants' => (bool)$product->has_variants],
            ]);

            return redirect()->route('management.stores.products', $product->store)->with('success', 'Product updated.');
        } catch (\Throwable $e) {
            Log::error('vendor.product.update_failed', ['error' => $e->getMessage(), 'product_id' => $product->id]);
            return back()->with('error', 'Unable to update product.')->withInput();
        }
    }

    public function show(Request $request, Product $product): View|RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->ownsProduct($product, $user)) {
            return redirect()->route('management.auth.login');
        }

        $product->load(['images', 'store', 'category', 'variants.sizeUnit', 'variants.weightUnit', 'section.warehouse', 'sizeUnit', 'weightUnit']);
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

        $backUrl = route('management.products.index', ['user' => $user]);
        $breadcrumbs = [['label' => 'Dashboard', 'url' => route('management.dashboard')], ['label' => 'Products', 'url' => route('management.products.index')], ['label' => $product->name]];
        return view('management.products.show', compact('user', 'product', 'priceInfo', 'priceInfoSymbol', 'backUrl', 'currencySymbols', 'breadcrumbs'));
    }

    public function updateStatus(Request $request, Product $product): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->ownsProduct($product, $user)) {
            return redirect()->route('management.auth.login');
        }

        $data = $request->validate(['status' => 'required|in:active,inactive']);
        $product->update(['status' => $data['status']]);
        $message = $data['status'] === 'active' ? 'Product activated' : 'Product deactivated';
        return redirect()->route('management.stores.products', $product->store)->with('success', $message);
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $user = $request->user();
        if (!$user || !$this->ownsProduct($product, $user)) {
            return redirect()->route('management.auth.login');
        }

        foreach ($product->images as $img) {
            try { Storage::disk('public')->delete($img->path); } catch (\Throwable $e) {}
        }
        $product->delete();
        return redirect()->route('management.stores.products', $product->store)->with('success', 'Product deleted.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user) return redirect()->route('management.auth.login');

        $ids = $request->input('product_ids', []);
        if (empty($ids)) {
            return back()->with('error', 'No products selected.');
        }

        $products = Product::whereIn('id', $ids)->get();
        $deleted = 0;

        foreach ($products as $product) {
            if (!$this->ownsProduct($product, $user)) continue;

            foreach ($product->images as $img) {
                try { Storage::disk('public')->delete($img->path); } catch (\Throwable $e) {}
            }
            $product->delete();
            $deleted++;
        }

        return back()->with('success', "{$deleted} product(s) deleted.");
    }

    public function bulkUpdate(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user) return redirect()->route('management.auth.login');

        $products = $request->input('products', []);
        if (empty($products)) return back()->with('error', 'No products selected.');

        $validIds = Product::whereIn('id', array_column($products, 'id'))
            ->when($user->isRestrictedStaff(), fn($q) => $q->whereIn('store_id', $user->assignedStores()->pluck('id')))
            ->when(!$user->isRestrictedStaff(), function ($q) use ($user) {
                $storeIds = $this->userStoreIds($user);
                if (!empty($storeIds)) $q->whereIn('store_id', $storeIds);
            })
            ->pluck('id')->toArray();

        $updated = 0;
        foreach ($products as $item) {
            if (!in_array((int) ($item['id'] ?? 0), $validIds, true)) continue;

            $data = [];
            if (isset($item['amount']) && $item['amount'] !== '') $data['amount'] = $item['amount'];
            if (isset($item['quantity']) && $item['quantity'] !== '') $data['quantity'] = $item['quantity'];
            if (isset($item['status']) && in_array($item['status'], ['active', 'inactive'])) $data['status'] = $item['status'];

            if (!empty($data)) {
                Product::where('id', $item['id'])->update($data);
                $updated++;
            }
        }

        return back()->with('success', "{$updated} product(s) updated successfully.");
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $user = $request->user();
        if (!$user) return redirect()->route('management.auth.login');

        $ids = $request->input('product_ids', []);
        $status = $request->input('status');

        if (empty($ids) || !in_array($status, ['active', 'inactive'])) {
            return back()->with('error', 'Invalid request.');
        }

        $count = Product::whereIn('id', $ids)
            ->where(function ($q) use ($user) {
                if ($user->isRestrictedStaff()) {
                    $q->whereIn('store_id', $user->assignedStores()->pluck('id'));
                } else {
                    $storeIds = $this->userStoreIds($user);
                    if (!empty($storeIds)) $q->whereIn('store_id', $storeIds);
                }
            })
            ->update(['status' => $status]);

        $label = $status === 'active' ? 'activated' : 'deactivated';
        return back()->with('success', "{$count} product(s) {$label}.");
    }

    private function ownsProduct(Product $product, User $user): bool
    {
        if ($product->store_id && in_array((int) $product->store_id, $this->userStoreIds($user), true)) {
            return true;
        }

        if ($product->warehouse_id) {
            $warehouseIds = $user->isStaff()
                ? $user->assignedWarehouses()->pluck('warehouses.id')->all()
                : $user->warehouses()->pluck('id')->all();
            if (in_array((int) $product->warehouse_id, array_map('intval', $warehouseIds), true)) {
                return true;
            }
        }

        return false;
    }
}
