<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BulkCartController extends Controller
{
    /**
     * Get bulk cart contents
     */
    public function get()
    {
        $cart = session('bulk_cart', [
            'items' => [],
            'custom_items' => []
        ]);

        return response()->json([
            'success' => true,
            'cart' => $cart
        ]);
    }

    /**
     * Add product to bulk cart
     */
    public function add(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $product = Product::with(['currency', 'store'])->findOrFail($request->product_id);

        // Verify product has bulk pricing
        if (!$product->bulk_quantity || $product->bulk_quantity <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'This product does not have bulk pricing.'
            ], 400);
        }

        // Ensure quantity meets minimum bulk requirement
        $quantity = max($request->quantity, $product->bulk_quantity);
        
        // Calculate pricing
        $unitPrice = $product->bulk_price / $product->bulk_quantity;
        $subtotal = $unitPrice * $quantity;

        $cart = session('bulk_cart', ['items' => [], 'custom_items' => []]);
        
        $cart['items'][$product->id] = [
            'product_id' => $product->id,
            'name' => $product->name,
            'product_code' => $product->product_code,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'bulk_price' => $product->bulk_price,
            'bulk_quantity' => $product->bulk_quantity,
            'subtotal' => $subtotal,
            'currency_symbol' => $product->currency->symbol ?? '₦',
        ];

        session(['bulk_cart' => $cart]);

        Log::info('bulk_cart_item_added', [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => $quantity,
            'subtotal' => $subtotal
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product added to bulk cart',
            'cart' => $cart
        ]);
    }

    /**
     * Add custom product to bulk cart
     */
    public function addCustom(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1',
            'budgeted_amount' => 'required|numeric|min:0',
        ]);

        $cart = session('bulk_cart', ['items' => [], 'custom_items' => []]);
        
        $customItem = [
            'name' => $request->name,
            'quantity' => $request->quantity,
            'budgeted_amount' => $request->budgeted_amount,
        ];

        $cart['custom_items'][] = $customItem;
        session(['bulk_cart' => $cart]);

        Log::info('bulk_cart_custom_added', [
            'name' => $request->name,
            'quantity' => $request->quantity,
            'budgeted_amount' => $request->budgeted_amount
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Custom product added to bulk cart',
            'cart' => $cart
        ]);
    }

    /**
     * Sync custom items in bulk cart
     */
    public function syncCustom(Request $request)
    {
        $request->validate([
            'custom_items' => 'present|array',
            'custom_items.*.name' => 'required|string|max:255',
            'custom_items.*.quantity' => 'required|integer|min:1',
            'custom_items.*.budgeted_amount' => 'required|numeric|min:0',
        ]);

        $cart = session('bulk_cart', ['items' => [], 'custom_items' => []]);
        
        $cart['custom_items'] = $request->custom_items;
        session(['bulk_cart' => $cart]);

        Log::info('bulk_cart_custom_synced', [
            'count' => count($request->custom_items)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Custom items synced',
            'cart' => $cart
        ]);
    }

    /**
     * Update bulk cart item quantity
     */
    public function update(Request $request, $productId)
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $cart = session('bulk_cart', ['items' => [], 'custom_items' => []]);

        if (!isset($cart['items'][$productId])) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found in cart'
            ], 404);
        }

        $item = $cart['items'][$productId];
        $quantity = max($request->quantity, $item['bulk_quantity']);
        
        $cart['items'][$productId]['quantity'] = $quantity;
        $cart['items'][$productId]['subtotal'] = $item['unit_price'] * $quantity;

        session(['bulk_cart' => $cart]);

        return response()->json([
            'success' => true,
            'message' => 'Cart updated',
            'cart' => $cart
        ]);
    }

    /**
     * Remove item from bulk cart
     */
    public function remove($productId)
    {
        $cart = session('bulk_cart', ['items' => [], 'custom_items' => []]);

        if (isset($cart['items'][$productId])) {
            unset($cart['items'][$productId]);
            session(['bulk_cart' => $cart]);

            return response()->json([
                'success' => true,
                'message' => 'Item removed from cart',
                'cart' => $cart
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Product not found in cart'
        ], 404);
    }

    /**
     * Remove custom item from bulk cart
     */
    public function removeCustom($index)
    {
        $cart = session('bulk_cart', ['items' => [], 'custom_items' => []]);

        if (isset($cart['custom_items'][$index])) {
            array_splice($cart['custom_items'], $index, 1);
            session(['bulk_cart' => $cart]);

            return response()->json([
                'success' => true,
                'message' => 'Custom item removed from cart',
                'cart' => $cart
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Custom item not found'
        ], 404);
    }

    /**
     * Clear bulk cart
     */
    public function clear()
    {
        session()->forget('bulk_cart');

        return response()->json([
            'success' => true,
            'message' => 'Cart cleared'
        ]);
    }
}
