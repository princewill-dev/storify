<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function search(Request $request, Store $store): JsonResponse
    {
        $query = trim($request->input('q', ''));

        $products = Product::where('store_id', $store->id)
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->when($query !== '', function ($q) use ($query) {
                $q->where(function ($x) use ($query) {
                    $x->where('name', 'like', "%{$query}%")
                      ->orWhere('product_code', 'like', "%{$query}%");
                });
            })
            ->with(['images' => fn($q) => $q->orderBy('position')])
            ->latest()
            ->limit($query !== '' ? 20 : 30)
            ->get()
            ->map(fn($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'product_code' => $product->product_code,
                'amount' => (float) $product->amount,
                'quantity' => (int) $product->quantity,
                'image' => $product->images->first()
                    ? asset('storage/' . $product->images->first()->path)
                    : null,
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'products' => $products,
            ],
        ]);
    }
}
