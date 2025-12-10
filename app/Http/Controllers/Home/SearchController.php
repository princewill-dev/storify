<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SearchController extends Controller
{
    /**
     * Live search products within a store
     */
    public function liveSearch(Request $request, string $store_slug): JsonResponse
    {
        $query = $request->input('q', '');
        
        Log::info('Live search request', [
            'store_slug' => $store_slug,
            'query' => $query,
            'query_length' => strlen($query)
        ]);
        
        if (strlen($query) < 2) {
            Log::info('Search query too short, returning empty results');
            return response()->json(['products' => []]);
        }

        $store = Store::where('slug', $store_slug)->first();
        
        if (!$store) {
            Log::warning('Store not found', ['store_slug' => $store_slug]);
            return response()->json(['products' => []]);
        }

        Log::info('Store found', ['store_id' => $store->id, 'store_name' => $store->name]);

        $products = Product::with('images')
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                  ->orWhere('product_code', 'LIKE', "%{$query}%");
            })
            ->limit(10)
            ->get()
            ->map(function ($product) use ($store) {
                $firstImage = optional($product->images->first())->path;
                $imageUrl = $firstImage ? asset('storage/' . $firstImage) : null;
                
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'product_code' => $product->product_code,
                    'slug' => $product->slug,
                    'image' => $imageUrl,
                    'price' => $product->amount ?? 0,
                    'url' => route('home.products.show', [
                        'store_slug' => $store->slug,
                        'slug' => $product->slug,
                        'code' => $product->product_code
                    ])
                ];
            });

        Log::info('Search results', [
            'query' => $query,
            'count' => $products->count(),
            'products' => $products->pluck('name')->toArray()
        ]);

        return response()->json(['products' => $products]);
    }
}
