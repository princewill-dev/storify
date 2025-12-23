<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;

class VendorCategoryController extends Controller
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

        $storeIds = $this->vendorStoreIds($vendor);
        $selectedPublicStoreId = $request->query('store_id');
        $selectedStoreId = null;
        $selectedStore = null;

        if ($selectedPublicStoreId) {
            $selectedStore = $vendor->stores()
                ->where('store_id', $selectedPublicStoreId)
                ->first();
            
            if ($selectedStore) {
                $selectedStoreId = $selectedStore->id;
            }
        }

        $query = Category::with('store')
            ->whereIn('store_id', $storeIds);

        if ($selectedStoreId) {
            $query->where('store_id', $selectedStoreId);
        }

        $categories = $query->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('vendors.categories.index', [
            'vendor' => $vendor,
            'categories' => $categories,
            'stores' => $vendor->stores()->orderBy('name')->get(),
            'selectedStore' => $selectedStore,
        ]);
    }

    public function create(Request $request, Vendor $routeVendor): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor) {
            return redirect()->route('vendor.auth.login');
        }

        $stores = $vendor->stores()->orderBy('name')->get();
        $selectedPublicStoreId = $request->query('store_id');
        $selectedStoreId = null;

        if ($selectedPublicStoreId) {
            $selectedStoreId = $vendor->stores()
                ->where('store_id', $selectedPublicStoreId)
                ->value('id');
        }

        return view('vendors.categories.create', [
            'vendor' => $vendor,
            'stores' => $stores,
            'selectedStoreId' => $selectedStoreId,
        ]);
    }

    public function store(Request $request, Vendor $routeVendor): RedirectResponse|JsonResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor) {
            if ($request->wantsJson() || $request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }
            return redirect()->route('vendor.auth.login');
        }

        $data = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'name' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
        ]);

        $storeIds = $this->vendorStoreIds($vendor);
        if (!in_array((int)$data['store_id'], $storeIds, true)) {
            if ($request->wantsJson() || $request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'Invalid store selection.'], 422);
            }
            return back()->with('error', 'Invalid store selection.')->withInput();
        }

        $store = Store::find($data['store_id']);
        $data['slug'] = Str::slug($data['name']) . '-' . substr((string)Str::uuid(), 0, 6);

        $category = Category::create($data);

        Log::info('vendor.category.created', ['vendor_id' => $vendor->id, 'category_id' => $category->id]);

        if ($request->wantsJson() || $request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Category created successfully.',
                'category' => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'slug' => $category->slug,
                    'status' => $category->status,
                ]
            ], 201);
        }

        return redirect()->route('vendor.categories.index', ['vendor' => $vendor, 'store_id' => $store->store_id])->with('success', 'Category created.');
    }

    public function edit(Request $request, Vendor $routeVendor, Category $category): View|RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->ownsCategory($category, $vendor)) {
            return redirect()->route('vendor.auth.login');
        }

        $stores = $vendor->stores()->orderBy('name')->get();

        return view('vendors.categories.edit', [
            'vendor' => $vendor,
            'category' => $category,
            'stores' => $stores,
        ]);
    }

    public function update(Request $request, Vendor $routeVendor, Category $category): RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->ownsCategory($category, $vendor)) {
            return redirect()->route('vendor.auth.login');
        }

        $data = $request->validate([
            'store_id' => 'required|exists:stores,id',
            'name' => 'required|string|max:255',
            'status' => 'required|in:active,inactive',
        ]);

        $storeIds = $this->vendorStoreIds($vendor);
        if (!in_array((int)$data['store_id'], $storeIds, true)) {
            return back()->with('error', 'Invalid store selection.')->withInput();
        }

        if ($category->name !== $data['name']) {
            $data['slug'] = Str::slug($data['name']) . '-' . substr((string)Str::uuid(), 0, 6);
        }

        $category->update($data);

        Log::info('vendor.category.updated', ['vendor_id' => $vendor->id, 'category_id' => $category->id]);

        return redirect()->route('vendor.categories.index', ['vendor' => $vendor, 'store_id' => $category->store->store_id])->with('success', 'Category updated.');
    }

    public function destroy(Request $request, Vendor $routeVendor, Category $category): RedirectResponse
    {
        $vendor = $this->resolveVendor($request, $routeVendor);
        if (!$vendor || !$this->ownsCategory($category, $vendor)) {
            return redirect()->route('vendor.auth.login');
        }

        $category->delete();

        Log::info('vendor.category.deleted', ['vendor_id' => $vendor->id, 'category_id' => $category->id]);

        return redirect()->route('vendor.categories.index', ['vendor' => $vendor])->with('success', 'Category deleted.');
    }

    private function ownsCategory(Category $category, Vendor $vendor): bool
    {
        return in_array((int)$category->store_id, $this->vendorStoreIds($vendor), true);
    }
}
