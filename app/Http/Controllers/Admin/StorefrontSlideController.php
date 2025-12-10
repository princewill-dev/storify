<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\Product;
use App\Models\StorefrontSlide;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class StorefrontSlideController extends Controller
{
    public function index(Store $store): View
    {
        $slides = StorefrontSlide::where('store_id', $store->id)
            ->with(['product.images'])
            ->orderBy('position')
            ->get();
        $products = Product::where('store_id', $store->id)->orderBy('name')->get(['id','name','product_code','slug','amount']);
        return view('admin.storefront_slides.index', compact('store','slides','products'));
    }

    public function store(Request $request, Store $store)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'status' => 'required|string|max:50',
        ]);
        $data['store_id'] = $store->id;
        $data['position'] = (int) (StorefrontSlide::where('store_id', $store->id)->max('position') + 1);
        StorefrontSlide::create($data);
        return back()->with('success', 'Slide created');
    }

    public function update(Request $request, Store $store, StorefrontSlide $slide)
    {
        abort_unless($slide->store_id === $store->id, 404);
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'status' => 'required|string|max:50',
        ]);
        $slide->update($data);
        return back()->with('success', 'Slide updated');
    }

    public function destroy(Store $store, StorefrontSlide $slide)
    {
        abort_unless($slide->store_id === $store->id, 404);
        $slide->delete();
        return back()->with('success', 'Slide deleted');
    }

    public function searchProducts(Request $request, Store $store)
    {
        $q = trim((string) $request->query('q', ''));
        $limit = (int) min(max((int) $request->query('limit', 10), 1), 25);
        $query = Product::where('store_id', $store->id)
            ->orderBy('name');
        if ($q !== '') {
            $query->where(function($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                  ->orWhere('product_code', 'like', "%$q%")
                  ->orWhere('slug', 'like', "%$q%");
            });
        }
        $products = $query->take($limit)->get(['id','name','product_code','slug','amount','status']);
        return response()->json($products);
    }

    public function reorder(Request $request, Store $store)
    {
        $data = $request->validate([
            'order' => 'required|array',
            'order.*' => 'integer|exists:storefront_slides,id',
        ]);
        $position = 0;
        Log::info('Reordering storefront slides', [
            'store_id' => $store->id,
            'order_count' => count($data['order']),
        ]);
        foreach ($data['order'] as $slideId) {
            StorefrontSlide::where('id', $slideId)->where('store_id', $store->id)->update(['position' => $position++]);
        }
        return response()->json(['ok' => true]);
    }

    // API: list products for a store (lazy-loaded in modal)
    public function apiListProducts(Request $request, Store $store)
    {
        $q = trim((string) $request->query('q', ''));
        $perPage = (int) min(max((int) $request->query('per_page', 20), 5), 50);
        $query = Product::where('store_id', $store->id)->with(['images'])->orderBy('name');
        if ($q !== '') {
            $query->where(function($x) use ($q) {
                $x->where('name', 'like', "%$q%")
                  ->orWhere('product_code', 'like', "%$q%")
                  ->orWhere('slug', 'like', "%$q%");
            });
        }
        $paginator = $query->paginate($perPage, ['id','name','product_code','slug','amount','status']);
        Log::info('API list products for slides', [
            'store_id' => $store->id,
            'q' => $q ? str(substr($q,0,50)) : null,
            'page' => $request->query('page', 1),
            'per_page' => $perPage,
        ]);
        $data = $paginator->getCollection()->map(function(Product $p){
            $primary = $p->primaryImage();
            return [
                'id' => $p->id,
                'name' => $p->name,
                'product_code' => $p->product_code,
                'slug' => $p->slug,
                'amount' => $p->amount,
                'status' => $p->status,
                'primary_image_path' => $primary?->path,
            ];
        });
        return response()->json([
            'data' => $data,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]);
    }

    // API: bulk create slides from selected product IDs
    public function apiBulkStore(Request $request, Store $store)
    {
        Log::info('API bulk add slides hit', [
            'store_id' => $store->id,
            'has_product_ids' => $request->has('product_ids'),
            'product_ids_count' => is_array($request->input('product_ids')) ? count($request->input('product_ids')) : 0,
            'status_present' => $request->filled('status'),
        ]);
        try {
            $data = $request->validate([
                'product_ids' => 'required|array|min:1',
                'product_ids.*' => 'integer|exists:products,id',
                'status' => 'required|string|max:50',
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            Log::warning('API bulk add slides validation failed', [
                'store_id' => $store->id,
                'errors' => $ve->errors(),
            ]);
            throw $ve;
        }
        Log::info('API bulk add slides request', [
            'store_id' => $store->id,
            'product_ids_count' => count($data['product_ids']),
            'status' => $data['status'],
            'user_id' => optional($request->user())->id,
        ]);
        $currentMax = (int) StorefrontSlide::where('store_id', $store->id)->max('position');
        $created = [];
        $skipped = [];
        $productIds = Product::whereIn('id', $data['product_ids'])->where('store_id', $store->id)->pluck('id')->all();
        foreach ($data['product_ids'] as $pid) {
            if (!in_array($pid, $productIds, true)) { $skipped[] = $pid; continue; }
            $currentMax++;
            try {
                $created[] = StorefrontSlide::create([
                    'store_id' => $store->id,
                    'product_id' => $pid,
                    'status' => $data['status'],
                    'position' => $currentMax,
                ])->id;
            } catch (\Throwable $e) {
                Log::error('Failed creating slide', [
                    'store_id' => $store->id,
                    'product_id' => $pid,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        Log::info('API bulk add slides result', [
            'created_count' => count($created),
            'skipped_count' => count($skipped),
        ]);
        return response()->json(['ok' => true, 'created_ids' => $created, 'skipped' => $skipped]);
    }
}
