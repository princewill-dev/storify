<?php

namespace App\Http\Controllers\Cart;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Store;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CartApiController extends Controller
{
    private function guestToken(Request $request): string
    {
        $token = (string)$request->cookie('guest_token');
        if (!$token) {
            $token = Str::uuid()->toString();
            Cookie::queue('guest_token', $token, 60 * 24 * 30);
        }
        return $token;
    }

    private function resolveStore(string $store_slug): Store
    {
        return Store::where('slug', $store_slug)->firstOrFail();
    }

    private function resolveCart(Request $request, Store $store): Cart
    {
        // Use customer guard for frontend users
        $userId = auth()->guard('customer')->id();
        $token = $this->guestToken($request);
        $query = Cart::query()->where('store_id', $store->id)->where('status', 'active');
        if ($userId) {
            $query->where(function($q) use ($userId, $token) {
                $q->where('user_id', $userId)->orWhere('guest_token', $token);
            });
        } else {
            $query->where('guest_token', $token);
        }
        $cart = $query->first();
        if (!$cart) {
            $cart = Cart::create([
                'store_id' => $store->id,
                'user_id' => $userId,
                'guest_token' => $userId ? null : $token,
                'currency' => 'NGN',
                'status' => 'active',
            ]);
            
            Log::info('cart_created', [
                'cart_id' => $cart->id,
                'store_id' => $store->id,
                'user_id' => $userId,
                'guest_token' => $userId ? null : $token,
                'is_guest' => !$userId,
            ]);
        } else if ($userId && !$cart->user_id) {
            $cart->user_id = $userId;
            $cart->guest_token = null;
            $cart->save();
            
            Log::info('cart_transferred_to_user', [
                'cart_id' => $cart->id,
                'user_id' => $userId,
            ]);
        }
        return $cart->load('items');
    }

    private function cartPayload(Cart $cart): array
    {
        $items = $cart->items()->with('product')->get()->map(function(CartItem $i){
            $product = $i->product;
            $image = null;
            if ($product) {
                $imageModel = $product->images()->first();
                $image = $imageModel ? $imageModel->path : null;
            }

            $isBulk = $product && $product->bulk_quantity > 0 && $i->qty >= $product->bulk_quantity;

            return [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'name' => $i->name,
                'qty' => $i->qty,
                'unit_amount' => $i->unit_amount,
                'line_subtotal' => $i->line_subtotal,
                'image' => $image,
                'slug' => $product->slug ?? null,
                'code' => $product->product_code ?? null,
                'is_bulk' => $isBulk,
                'max_stock' => ($product && !$product->has_variants && !is_null($product->quantity))
                    ? (int)$product->quantity
                    : null,
            ];
        });
        return [
            'id' => $cart->id,
            'store_id' => $cart->store_id,
            'item_count' => $cart->item_count,
            'subtotal' => $cart->subtotal,
            'discount_total' => $cart->discount_total,
            'tax_total' => $cart->tax_total,
            'total' => $cart->total,
            'items' => $items,
        ];
    }

    public function get(Request $request, string $store_subdomain)
    {
        $store = $this->resolveStore($store_subdomain);
        $cart = $this->resolveCart($request, $store);
        $cart->recalcTotals();
        return response()->json($this->cartPayload($cart));
    }

    public function add(Request $request, string $store_subdomain)
    {
        $data = $request->validate([
            'product_id' => ['required','exists:products,id'],
            'qty' => ['nullable','integer','min:1'],
            'variant_key' => ['nullable','string','max:100'],
        ]);
        $qty = max(1, (int)($data['qty'] ?? 1));
        $store = $this->resolveStore($store_subdomain);
        $product = Product::findOrFail($data['product_id']);
        if ((int)$product->store_id !== (int)$store->id) {
            return response()->json(['message' => 'Product not in this store'], 422);
        }

        $cart = $this->resolveCart($request, $store);

        // For non-variant products, check stock against total qty that will be in cart
        if (!$product->has_variants && !is_null($product->quantity)) {
            $existingLine = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->where('variant_key', $data['variant_key'] ?? null)
                ->first();

            $existingCartQty = $existingLine ? (int)$existingLine->qty : 0;
            $totalQtyAfterAdd = $existingCartQty + $qty;

            if ($totalQtyAfterAdd > (int)$product->quantity) {
                $available = max(0, (int)$product->quantity - $existingCartQty);
                return response()->json([
                    'message' => $available > 0
                        ? "Only {$available} more unit(s) can be added (you already have {$existingCartQty} in cart)."
                        : 'You have already added the maximum available stock to your cart.',
                    'max_stock' => (int)$product->quantity,
                    'cart_qty' => $existingCartQty,
                ], 422);
            }
        }

        return DB::transaction(function() use ($cart, $product, $data, $qty) {
            $line = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->where('variant_key', $data['variant_key'] ?? null)
                ->first();
            Log::info('cart_add_request', [
                'product_id' => $product->id,
                'qty' => $qty,
                'bulk_quantity' => $product->bulk_quantity,
                'bulk_price' => $product->bulk_price,
                'base_amount' => $product->amount
            ]);

            // Check for bulk pricing
            if ($product->bulk_quantity > 0 && $qty >= $product->bulk_quantity && $product->bulk_price > 0) {
                // Bulk price is in Naira (decimal), so divide by qty to get unit price in Naira, then * 100 for kobo
                $unit = (int) round(($product->bulk_price / $product->bulk_quantity) * 100);
                Log::info('cart_add_bulk_applied', ['unit_kobo' => $unit]);
            } else {
                // Normalize product amount to kobo (integer)
                $raw = $product->amount ?? 0;
                if (is_string($raw)) { $raw = trim($raw); }
                if (is_numeric($raw)) {
                    $unit = (strpos((string)$raw, '.') !== false) ? (int) round(((float)$raw) * 100) : (int) $raw;
                } else {
                    $unit = 0;
                }
                Log::info('cart_add_normal_applied', ['raw' => $raw, 'unit_kobo' => $unit]);
            }
            if ($line) {
                $line->qty = $line->qty + $qty;
                $line->unit_amount = $unit;
                $line->line_subtotal = $line->qty * $unit;
                $line->save();
            } else {
                $line = CartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'variant_key' => $data['variant_key'] ?? null,
                    'name' => $product->name,
                    'unit_amount' => $unit,
                    'qty' => $qty,
                    'line_subtotal' => $qty * $unit,
                ]);
            }
            $cart->load('items');
            $cart->recalcTotals();
            $payload = $this->cartPayload($cart);
            $payload['max_stock'] = is_null($product->quantity) ? null : (int)$product->quantity;
            $payload['cart_qty'] = (int)$line->qty;
            return response()->json($payload);
        });
    }

    public function buyNow(Request $request, string $store_subdomain)
    {
        $data = $request->validate([
            'product_id' => ['required','exists:products,id'],
            'qty' => ['nullable','integer','min:1'],
            'variant_key' => ['nullable','string','max:100'],
        ]);
        $qty = max(1, (int)($data['qty'] ?? 1));
        $store = $this->resolveStore($store_subdomain);
        $product = Product::findOrFail($data['product_id']);
        if ((int)$product->store_id !== (int)$store->id) {
            return response()->json(['message' => 'Product not in this store'], 422);
        }

        // Check stock availability
        if (!$product->has_variants && !is_null($product->quantity)) {
            if ($qty > (int)$product->quantity) {
                return response()->json([
                    'message' => 'Requested quantity exceeds available stock.',
                ], 422);
            }
        }

        return DB::transaction(function() use ($product, $data, $qty, $store, $store_subdomain, $request) {
            // Create an isolated cart specifically for Buy Now
            $token = bin2hex(random_bytes(16)); // 32 chars
            
            $cart = Cart::create([
                'store_id' => $store->id,
                'user_id' => auth()->guard('customer')->id() ?: null,
                'guest_token' => auth()->guard('customer')->id() ? null : $request->cookie('guest_token'),
                'status' => 'active',
                'checkout_token' => $token,
                'meta' => ['is_buy_now' => true],
            ]);

            // Calculate unit price
            if ($product->bulk_quantity > 0 && $qty >= $product->bulk_quantity && $product->bulk_price > 0) {
                $unit = (int) round(($product->bulk_price / $product->bulk_quantity) * 100);
            } else {
                $raw = $product->amount ?? 0;
                if (is_string($raw)) { $raw = trim($raw); }
                if (is_numeric($raw)) {
                    $unit = (strpos((string)$raw, '.') !== false) ? (int) round(((float)$raw) * 100) : (int) $raw;
                } else {
                    $unit = 0;
                }
            }

            // Create cart item
            CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $product->id,
                'variant_key' => $data['variant_key'] ?? null,
                'name' => $product->name,
                'unit_amount' => $unit,
                'qty' => $qty,
                'line_subtotal' => $qty * $unit,
            ]);

            $cart->load('items');
            $cart->recalcTotals();

            // Generate redirect URL
            if ($request->routeIs('local.*') || config('app.env') === 'local') {
                $redirectUrl = route('local.checkout.index', [
                    'store_subdomain' => $store_subdomain,
                    'token' => $token
                ]);
            } else {
                $redirectUrl = route('checkout.index', [
                    'store_subdomain' => $store_subdomain,
                    'token' => $token
                ]);
            }

            return response()->json([
                'success' => true,
                'redirect_url' => $redirectUrl
            ]);
        });
    }

    public function updateItem(Request $request, string $store_subdomain, CartItem $item)
    {
        $data = $request->validate([
            'qty' => ['required','integer','min:1']
        ]);
        $store = $this->resolveStore($store_subdomain);
        if ((int)$item->cart->store_id !== (int)$store->id) {
            return response()->json(['message' => 'Wrong store'], 403);
        }
        $item->qty = (int)$data['qty'];
        
        // Recalculate unit amount based on new qty (Bulk Pricing)
        $product = $item->product;
        if ($product) {
            Log::info('cart_update_request', [
                'item_id' => $item->id,
                'new_qty' => $item->qty,
                'bulk_quantity' => $product->bulk_quantity,
                'bulk_price' => $product->bulk_price
            ]);

            if ($product->bulk_quantity > 0 && $item->qty >= $product->bulk_quantity && $product->bulk_price > 0) {
                $unit = (int) round(($product->bulk_price / $product->bulk_quantity) * 100);
                Log::info('cart_update_bulk_applied', ['unit_kobo' => $unit]);
            } else {
                $raw = $product->amount ?? 0;
                if (is_string($raw)) { $raw = trim($raw); }
                if (is_numeric($raw)) {
                    $unit = (strpos((string)$raw, '.') !== false) ? (int) round(((float)$raw) * 100) : (int) $raw;
                } else {
                    $unit = 0;
                }
                Log::info('cart_update_normal_applied', ['raw' => $raw, 'unit_kobo' => $unit]);
            }
            $item->unit_amount = $unit;
        }

        $item->line_subtotal = $item->qty * (int)$item->unit_amount;
        $item->save();
        $cart = $item->cart()->with('items')->first();
        $cart->recalcTotals();
        return response()->json($this->cartPayload($cart));
    }

    public function removeItem(Request $request, string $store_subdomain, CartItem $item)
    {
        $store = $this->resolveStore($store_subdomain);
        if ((int)$item->cart->store_id !== (int)$store->id) {
            return response()->json(['message' => 'Wrong store'], 403);
        }
        $cart = $item->cart;
        $item->delete();
        $cart->load('items');
        $cart->recalcTotals();
        return response()->json($this->cartPayload($cart));
    }

    public function clear(Request $request, string $store_subdomain)
    {
        $store = $this->resolveStore($store_subdomain);
        $cart = $this->resolveCart($request, $store);
        $cart->items()->delete();
        $cart->load('items');
        $cart->recalcTotals();
        return response()->json($this->cartPayload($cart));
    }
}
