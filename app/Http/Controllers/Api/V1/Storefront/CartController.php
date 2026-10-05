<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Storefront\Concerns\ResolvesStorefrontContext;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends ApiController
{
    use ResolvesStorefrontContext;

    public function show(Request $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);
        $cart = $this->resolveCart($store, $request, create: false);

        return $this->ok($this->cartPayload($cart, $this->guestToken($request)));
    }

    public function add(Request $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'qty' => ['nullable', 'integer', 'min:1'],
            'variant_key' => ['nullable', 'string', 'max:100'],
        ]);

        $qty = max(1, (int) ($data['qty'] ?? 1));

        $product = Product::where('store_id', $store->id)->findOrFail($data['product_id']);

        if (! $product->is_digital && ! $product->has_variants && ! is_null($product->quantity)
            && $qty > (int) $product->quantity) {
            return $this->error('Requested quantity exceeds available stock.', 422);
        }

        $cart = $this->resolveCart($store, $request);

        DB::transaction(function () use ($cart, $product, $data, $qty) {
            $line = CartItem::where('cart_id', $cart->id)
                ->where('product_id', $product->id)
                ->where('variant_key', $data['variant_key'] ?? null)
                ->first();

            $raw = $product->amount ?? 0;
            $unit = is_numeric($raw)
                ? ((str_contains((string) $raw, '.') ? (int) round(((float) $raw) * 100) : (int) $raw))
                : 0;

            if ($product->bulk_quantity > 0 && $qty >= $product->bulk_quantity && $product->bulk_price > 0) {
                $unit = (int) round(($product->bulk_price / $product->bulk_quantity) * 100);
            }

            if ($product->is_digital && $line) {
                $line->update(['qty' => 1, 'line_subtotal' => $unit]);
            } elseif ($line) {
                $line->update([
                    'qty' => $line->qty + $qty,
                    'line_subtotal' => ($line->qty + $qty) * $line->unit_amount,
                ]);
            } else {
                CartItem::create([
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                    'variant_key' => $data['variant_key'] ?? null,
                    'name' => $product->name,
                    'unit_amount' => $unit,
                    'qty' => $qty,
                    'line_subtotal' => $unit * $qty,
                ]);
            }

            $cart->recalcTotals();
        });

        return $this->ok($this->cartPayload($cart->fresh(), $this->guestToken($request)), 'Added to cart.');
    }

    public function updateItem(Request $request, string $store, CartItem $item): JsonResponse
    {
        $store = $this->resolveStore($store);
        $cart = $this->resolveCart($store, $request, create: false);

        if (! $cart || (int) $item->cart_id !== (int) $cart->id) {
            abort(404);
        }

        $data = $request->validate(['qty' => ['required', 'integer', 'min:0']]);

        if ($data['qty'] === 0) {
            $item->delete();
        } else {
            $item->update(['qty' => $data['qty'], 'line_subtotal' => $data['qty'] * $item->unit_amount]);
        }

        $cart->recalcTotals();

        return $this->ok($this->cartPayload($cart->fresh(), $this->guestToken($request)), 'Cart updated.');
    }

    public function removeItem(Request $request, string $store, CartItem $item): JsonResponse
    {
        $store = $this->resolveStore($store);
        $cart = $this->resolveCart($store, $request, create: false);

        if (! $cart || (int) $item->cart_id !== (int) $cart->id) {
            abort(404);
        }

        $item->delete();
        $cart->recalcTotals();

        return $this->ok($this->cartPayload($cart->fresh(), $this->guestToken($request)), 'Item removed.');
    }

    public function clear(Request $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);
        $cart = $this->resolveCart($store, $request, create: false);

        if ($cart) {
            $cart->items()->delete();
            $cart->recalcTotals();
        }

        return $this->ok($this->cartPayload($cart?->fresh(), $this->guestToken($request)), 'Cart cleared.');
    }
}
