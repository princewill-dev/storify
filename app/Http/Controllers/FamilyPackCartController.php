<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Session;

class FamilyPackCartController extends Controller
{
    private const CART_SESSION_KEY = 'family_pack_cart';
    private const CUSTOM_ITEMS_KEY = 'family_pack_custom_items';

    /**
     * Add a product to the family pack cart
     */
    public function add(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'store_id' => 'required|exists:stores,id',
        ]);

        $product = Product::findOrFail($request->product_id);
        $store = Store::find($request->store_id);
        
        if (!$store) {
            Log::error('Family Pack: Store not found', [
                'store_id' => $request->store_id,
                'product_id' => $request->product_id,
            ]);
            
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Store not found. Please refresh the page and try again.',
                ], 404);
            }
            
            return back()->with('error', 'Store not found.');
        }

        // Check if product belongs to store
        if ($product->store_id !== $store->id) {
            Log::warning('Family Pack: Product does not belong to store', [
                'product_id' => $product->id,
                'product_store_id' => $product->store_id,
                'requested_store_id' => $store->id,
            ]);
            
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Product does not belong to this store.',
                ], 400);
            }
            
            return back()->with('error', 'Product does not belong to this store.');
        }

        $cart = Session::get(self::CART_SESSION_KEY, []);
        
        // Ensure cart is for the same store
        if (!empty($cart) && isset($cart['store_id']) && $cart['store_id'] !== $store->id) {
            return back()->with('error', 'You can only add items from one store to your family pack.');
        }

        // Initialize cart structure
        if (empty($cart)) {
            $cart = [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'items' => [],
            ];
        }

        // Check if product already exists in cart
        $productKey = 'product_' . $product->id;
        if (isset($cart['items'][$productKey])) {
            $cart['items'][$productKey]['quantity'] += $request->quantity;
        } else {
            $cart['items'][$productKey] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'product_code' => $product->code,
                'unit_price' => $product->amount,
                'quantity' => $request->quantity,
                'is_custom' => false,
            ];
        }

        Session::put(self::CART_SESSION_KEY, $cart);

        Log::info('Family Pack: Product added', [
            'product_id' => $product->id,
            'quantity' => $request->quantity,
            'store_id' => $store->id,
            'customer_id' => auth('customer')->id() ?? 'guest',
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Product added to family pack cart!',
                'cart' => $cart,
                'count' => self::getItemCount(),
            ]);
        }

        return back()->with('success', 'Product added to family pack cart!');
    }

    /**
     * Add a custom item to the family pack cart
     */
    public function addCustom(Request $request)
    {
        $request->validate([
            'product_name' => 'required|string|max:255',
            'quantity' => 'required|integer|min:1',
            'budgeted_amount' => 'required|numeric|min:0',
            'store_id' => 'required|exists:stores,id',
        ]);

        $store = Store::findOrFail($request->store_id);
        $cart = Session::get(self::CART_SESSION_KEY, []);

        // Ensure cart is for the same store
        if (!empty($cart) && isset($cart['store_id']) && $cart['store_id'] !== $store->id) {
            return back()->with('error', 'You can only add items from one store to your family pack.');
        }

        // Initialize cart structure
        if (empty($cart)) {
            $cart = [
                'store_id' => $store->id,
                'store_name' => $store->name,
                'items' => [],
            ];
        }

        // Generate unique key for custom item
        $customKey = 'custom_' . uniqid();

        $cart['items'][$customKey] = [
            'product_id' => null,
            'product_name' => $request->product_name,
            'product_code' => null,
            'quantity' => $request->quantity,
            'budgeted_amount' => $request->budgeted_amount,
            'is_custom' => true,
        ];

        Session::put(self::CART_SESSION_KEY, $cart);

        Log::info('Family Pack: Custom item added', [
            'product_name' => $request->product_name,
            'quantity' => $request->quantity,
            'budgeted_amount' => $request->budgeted_amount,
            'store_id' => $store->id,
            'customer_id' => auth('customer')->id() ?? 'guest',
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Custom item added to family pack cart!',
                'cart' => $cart,
                'count' => self::getItemCount(),
            ]);
        }

        return back()->with('success', 'Custom item added to family pack cart!');
    }

    /**
     * Update item quantity in cart
     */
    public function update(Request $request)
    {
        $request->validate([
            'item_key' => 'required|string',
            'quantity' => 'required|integer|min:1',
        ]);

        $cart = Session::get(self::CART_SESSION_KEY, []);

        if (!isset($cart['items'][$request->item_key])) {
            return back()->with('error', 'Item not found in cart.');
        }

        $cart['items'][$request->item_key]['quantity'] = $request->quantity;
        $cart['items'][$request->item_key]['quantity'] = $request->quantity;
        Session::put(self::CART_SESSION_KEY, $cart);

        Log::info('Family Pack: Item quantity updated', [
            'item_key' => $request->item_key,
            'quantity' => $request->quantity,
            'customer_id' => auth('customer')->id() ?? 'guest',
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Quantity updated.',
                'cart' => $cart,
                'count' => self::getItemCount(),
                'subtotal' => self::getSubtotal(),
            ]);
        }

        return back()->with('success', 'Quantity updated.');
    }

    /**
     * Remove item from cart
     */
    public function remove(Request $request)
    {
        $request->validate([
            'item_key' => 'required|string',
        ]);

        $cart = Session::get(self::CART_SESSION_KEY, []);

        if (isset($cart['items'][$request->item_key])) {
            unset($cart['items'][$request->item_key]);
            Session::put(self::CART_SESSION_KEY, $cart);

            Log::info('Family Pack: Item removed', [
                'item_key' => $request->item_key,
                'customer_id' => auth('customer')->id() ?? 'guest',
            ]);

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Item removed from cart.',
                    'cart' => $cart,
                    'count' => self::getItemCount(),
                    'subtotal' => self::getSubtotal(),
                ]);
            }

            return back()->with('success', 'Item removed from cart.');
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Item not found in cart.',
            ], 404);
        }

        return back()->with('error', 'Item not found in cart.');
    }

    /**
     * Clear entire cart
     */
    public function clear()
    {
        Session::forget(self::CART_SESSION_KEY);
        Session::forget(self::CUSTOM_ITEMS_KEY);
        
        Log::info('Family Pack: Cart cleared', [
            'customer_id' => auth('customer')->id() ?? 'guest',
        ]);

        if (request()->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Family pack cart cleared.',
                'count' => 0,
            ]);
        }

        return back()->with('success', 'Family pack cart cleared.');
    }

    /**
     * Get current cart data
     */
    public function getCartData()
    {
        return response()->json([
            'success' => true,
            'cart' => self::getCart(),
            'count' => self::getItemCount(),
            'subtotal' => self::getSubtotal(),
        ]);
    }

    /**
     * Get cart contents
     */
    public static function getCart()
    {
        return Session::get(self::CART_SESSION_KEY, []);
    }

    /**
     * Get cart item count
     */
    public static function getItemCount()
    {
        $cart = self::getCart();
        return isset($cart['items']) ? count($cart['items']) : 0;
    }

    /**
     * Calculate cart subtotal
     */
    public static function getSubtotal()
    {
        $cart = self::getCart();
        $subtotal = 0;

        if (isset($cart['items'])) {
            foreach ($cart['items'] as $item) {
                if ($item['is_custom']) {
                    $subtotal += $item['budgeted_amount'];
                } else {
                    $subtotal += $item['unit_price'] * $item['quantity'];
                }
            }
        }

        return $subtotal;
    }
}
