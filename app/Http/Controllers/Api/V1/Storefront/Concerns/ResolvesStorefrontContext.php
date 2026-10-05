<?php

namespace App\Http\Controllers\Api\V1\Storefront\Concerns;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait ResolvesStorefrontContext
{
    protected function resolveStore(string $slug): Store
    {
        return Store::query()
            ->where('slug', $slug)
            ->where('status', '!=', 'deleted')
            ->firstOrFail();
    }

    protected function currentCustomer(Request $request): ?Customer
    {
        return auth('sanctum_customer')->user();
    }

    protected function guestToken(Request $request): string
    {
        $token = (string) $request->header('X-Guest-Token', '');

        if ($token === '') {
            $token = (string) $request->input('guest_token', '');
        }

        return $token !== '' ? $token : (string) Str::uuid();
    }

    protected function resolveCart(Store $store, Request $request, bool $create = true): ?Cart
    {
        $customer = $this->currentCustomer($request);
        $token = $this->guestToken($request);

        $query = Cart::query()
            ->where('store_id', $store->id)
            ->where('status', 'active');

        if ($customer) {
            $query->where(function ($q) use ($customer, $token) {
                $q->where('user_id', $customer->id)
                    ->orWhere('guest_token', $token);
            });
        } else {
            $query->where('guest_token', $token);
        }

        $cart = $query->latest()->first();

        if ($cart) {
            if ($customer && ! $cart->user_id) {
                $cart->update(['user_id' => $customer->id, 'user_type' => Customer::class]);
            }

            return $cart;
        }

        if (! $create) {
            return null;
        }

        return Cart::create([
            'store_id' => $store->id,
            'user_id' => $customer?->id,
            'user_type' => $customer ? Customer::class : null,
            'guest_token' => $customer ? null : $token,
            'currency' => 'NGN',
            'status' => 'active',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function cartPayload(?Cart $cart, string $guestToken): array
    {
        if (! $cart) {
            return ['guest_token' => $guestToken, 'items' => [], 'item_count' => 0, 'subtotal' => 0, 'total' => 0];
        }

        $cart->load(['items.product.images']);

        return [
            'guest_token' => $guestToken,
            'cart_id' => $cart->id,
            'checkout_token' => $cart->checkout_token,
            'item_count' => (int) $cart->item_count,
            'subtotal' => (int) $cart->subtotal,
            'total' => (int) $cart->total,
            'delivery_route_id' => $cart->delivery_route_id,
            'items' => $cart->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->name,
                'qty' => (int) $item->qty,
                'unit_amount' => (int) $item->unit_amount,
                'line_subtotal' => (int) $item->line_subtotal,
                'is_digital' => (bool) ($item->product?->is_digital),
                'image_url' => $item->product?->primaryImage()?->path
                    ? asset('storage/'.$item->product->primaryImage()->path)
                    : null,
            ])->values()->all(),
        ];
    }
}
