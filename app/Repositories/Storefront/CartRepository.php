<?php

namespace App\Repositories\Storefront;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Store;

/**
 * Reads for the public storefront cart.
 *
 * Both lookups earn their name through tenancy scope, not size: dropping the
 * store constraint on the product read would let a product id from another
 * store enter this store's cart (and its name, image and price leak through
 * the cart payload), and dropping the cart constraint on the line read would
 * let one cart's line be updated through another cart's request.
 *
 * Query building only — the add-to-cart transaction is opened by
 * CartService, exactly where the controller's DB::transaction sat, and
 * neither method aborts an HTTP request.
 */
final class CartRepository
{
    /**
     * The product an add may reference, scoped to the storefront being
     * visited. `findOrFail` keeps the controller's 404 for an unknown id;
     * a product that exists in a different store also 404s here.
     */
    public function findProductInStore(Store $store, int $productId): Product
    {
        return Product::query()
            ->where('store_id', $store->id)
            ->findOrFail($productId);
    }

    /**
     * The line an add matches on: same cart, same product, same variant key
     * (null means the no-variant line, matched with `whereNull`). This
     * identity decides insert-vs-update inside the add-to-cart transaction,
     * so the null handling is part of the contract rather than incidental.
     */
    public function findLine(Cart $cart, Product $product, ?string $variantKey): ?CartItem
    {
        return CartItem::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->where('variant_key', $variantKey)
            ->first();
    }
}
