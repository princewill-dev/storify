<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Storefront\Concerns\ResolvesStorefrontContext;
use App\Http\Requests\Storefront\AddCartItemRequest;
use App\Http\Requests\Storefront\UpdateCartItemRequest;
use App\Models\CartItem;
use App\Repositories\Storefront\CartRepository;
use App\Services\Storefront\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public storefront cart.
 *
 * Guest carts are keyed by the X-Guest-Token header (or a customer session);
 * the shared ResolvesStorefrontContext concern owns store/cart resolution and
 * the payload shape, and stays untouched because four other storefront
 * controllers also use it. This class keeps the HTTP contract: status codes,
 * message strings, and the 404 that a line must belong to the resolved cart
 * (`abort` never moves into the repository or service).
 *
 * Ordering is preserved. `add` still resolves the store, clamps qty to at
 * least 1, reads the store-scoped product and answers the stock 422 *before*
 * resolving (and possibly creating) the cart, so a rejected add leaves no
 * empty cart behind; the transaction still opens after that point and closes
 * before `fresh()` feeds the response. The one order change is the accepted
 * codebase-wide one: validation now runs during parameter resolution, so a
 * malformed body on an unknown store returns 422 rather than the old 404.
 *
 * MONEY — `products.amount` is a decimal(12,2) naira column and the unit kobo
 * written to `cart_items.unit_amount` is produced by CartService with
 * Naira::koboFromRounded's contract, exactly the `(int) round((float) $raw * 100)`
 * this controller carried inline. Response fields are untouched.
 *
 * Layer map: validation in AddCartItemRequest / UpdateCartItemRequest, the
 * tenancy-scoped product and line reads in CartRepository, the line write plus
 * totals recalc workflow (and its transaction) in CartService.
 */
class CartController extends ApiController
{
    use ResolvesStorefrontContext;

    public function __construct(
        private readonly CartRepository $repository,
        private readonly CartService $service,
    ) {}

    public function show(Request $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);
        $cart = $this->resolveCart($store, $request, create: false);

        return $this->ok($this->cartPayload($cart, $this->guestToken($request)));
    }

    public function add(AddCartItemRequest $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $data = $request->validated();
        $qty = max(1, (int) ($data['qty'] ?? 1));

        $product = $this->repository->findProductInStore($store, $data['product_id']);

        if (! $product->is_digital && ! $product->has_variants && ! is_null($product->quantity)
            && $qty > (int) $product->quantity) {
            return $this->error('Requested quantity exceeds available stock.', 422);
        }

        $cart = $this->resolveCart($store, $request);

        $this->service->addItem($cart, $product, $data['variant_key'] ?? null, $qty);

        return $this->ok($this->cartPayload($cart->fresh(), $this->guestToken($request)), 'Added to cart.');
    }

    public function updateItem(UpdateCartItemRequest $request, string $store, CartItem $item): JsonResponse
    {
        $store = $this->resolveStore($store);
        $cart = $this->resolveCart($store, $request, create: false);

        if (! $cart || (int) $item->cart_id !== (int) $cart->id) {
            abort(404);
        }

        $this->service->updateItem($cart, $item, $request->validated()['qty']);

        return $this->ok($this->cartPayload($cart->fresh(), $this->guestToken($request)), 'Cart updated.');
    }

    public function removeItem(Request $request, string $store, CartItem $item): JsonResponse
    {
        $store = $this->resolveStore($store);
        $cart = $this->resolveCart($store, $request, create: false);

        if (! $cart || (int) $item->cart_id !== (int) $cart->id) {
            abort(404);
        }

        $this->service->removeItem($cart, $item);

        return $this->ok($this->cartPayload($cart->fresh(), $this->guestToken($request)), 'Item removed.');
    }

    public function clear(Request $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);
        $cart = $this->resolveCart($store, $request, create: false);

        if ($cart) {
            $this->service->clear($cart);
        }

        return $this->ok($this->cartPayload($cart?->fresh(), $this->guestToken($request)), 'Cart cleared.');
    }
}
