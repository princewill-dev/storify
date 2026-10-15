<?php

namespace App\Http\Controllers\Api\V1\Storefront\Concerns;

use App\Exceptions\StorefrontOfflineException;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait ResolvesStorefrontContext
{
    /**
     * The one funnel every customer-facing storefront endpoint goes through.
     *
     * `firstOrFail()` already answers 404 for a slug that is unknown or
     * deleted; `has_website` is the owner's own off-switch, and it refuses with
     * the same status and a marker the SPA can read (see
     * StorefrontOfflineException). Both refusals look identical to a customer
     * probing addresses, which is the point.
     *
     * Every controller that `use`s this trait picks the gate up at once:
     * catalog, cart, checkout, tracking, support and downloads. That includes
     * `tracking`, which the storefront Worker calls — suppressing a switched-off
     * shop's tags is right, and the Worker is built to fail open.
     */
    protected function resolveStore(string $slug): Store
    {
        $store = Store::query()
            ->where('slug', $slug)
            ->where('status', '!=', 'deleted')
            ->firstOrFail();

        if (! $store->has_website) {
            throw new StorefrontOfflineException;
        }

        return $store;
    }

    protected function currentCustomer(Request $request): ?Customer
    {
        return auth('sanctum_customer')->user();
    }

    /**
     * The store a storefront auth request is aimed at, or null.
     *
     * A customer account belongs to exactly one business, so sign-up, sign-in
     * and password reset are meaningless without this. The canonical routes
     * carry it in the path, but the routes that were live before this existed
     * carry it nowhere — and they are still being called by a storefront that
     * has not been redeployed. So it is looked for in four places, in order,
     * and the last two exist purely to keep that deployed storefront working.
     *
     * Rather than pick a store at random when none of them answers — which is
     * the defect this whole change repairs — the caller is told to name one.
     */
    protected function resolveAuthStore(Request $request): ?Store
    {
        $slug = $this->authStoreSlug($request);

        return $slug ? $this->resolveStore($slug) : null;
    }

    /**
     * In order of trustworthiness, not convenience:
     *
     *   1. the route, on the canonical `storefront/{store}/auth/*` paths;
     *   2. an explicit `store` input or `X-Store-Slug` header, for clients that
     *      would rather be explicit than have the server infer;
     *   3. the `Origin` host — and this is the one that matters on deploy day,
     *      because the API is its own origin, so every browser request to it is
     *      cross-origin and carries one. That is what lets the storefront
     *      already in production keep signing customers in, unchanged, while
     *      the routes it calls are being retired underneath it.
     *
     * A server-to-server caller with none of the three gets null, and the
     * controller answers `store_unresolved`. Deliberately not a fourth rung
     * that guesses from the email: guessing is the bug.
     */
    protected function authStoreSlug(Request $request): ?string
    {
        $route = $request->route('store');

        if (is_string($route) && $route !== '') {
            return $route;
        }

        $input = $request->input('store');

        if (is_string($input) && $input !== '') {
            return $input;
        }

        $header = $request->header('X-Store-Slug');

        if (is_string($header) && $header !== '') {
            return $header;
        }

        return $this->slugFromOrigin($request);
    }

    /**
     * `https://swift-one.storify.ng` -> `swift-one`.
     *
     * Mirrors the resolution the storefront SPA does on its own hostname
     * (storify-storefront/src/lib/storeSlug.ts), including its label-count
     * fallback, so the two agree about which store a visitor is on rather than
     * about which one the server would prefer. Reserved names are refused
     * here as they are there: `app.storify.ng` is the management console, not
     * a store called `app`.
     */
    protected function slugFromOrigin(Request $request): ?string
    {
        $origin = $request->header('Origin') ?: $request->header('Referer');

        if (! is_string($origin) || $origin === '') {
            return null;
        }

        $host = parse_url($origin, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        $mainDomain = strtolower((string) config('frontend.storefront_main_domain'));

        if ($mainDomain !== '' && $host === $mainDomain) {
            return null;
        }

        if ($mainDomain !== '' && str_ends_with($host, '.'.$mainDomain)) {
            return $this->acceptableSlug(substr($host, 0, -(strlen($mainDomain) + 1)));
        }

        // Not under the configured domain. `swift-one.localhost` and
        // `swift-one.lvh.me` are how a storefront is reached locally and over a
        // tunnel, so they follow the same rule the SPA applies to them. The
        // label-count case covers any other three-label host.
        //
        // Deliberately narrow: a bare two-label host that is neither of those
        // (`example.com`) is not read as a store, because inventing a slug
        // there would answer 404 for a store that was never named, when the
        // honest answer is that the request named no store at all.
        if (str_ends_with($host, '.localhost') || str_ends_with($host, '.lvh.me')) {
            return $this->acceptableSlug(explode('.', $host)[0]);
        }

        $labels = explode('.', $host);

        return count($labels) >= 3 ? $this->acceptableSlug($labels[0]) : null;
    }

    /**
     * A candidate slug, or null if it is not one.
     *
     * Single-label only: `a.b.storify.ng` is not a tenant, and neither is a
     * reserved name.
     */
    protected function acceptableSlug(string $slug): ?string
    {
        $slug = strtolower(trim($slug));

        if ($slug === '' || str_contains($slug, '.')) {
            return null;
        }

        $reserved = config('storefront.reserved_subdomains', []);

        return in_array($slug, $reserved, true) ? null : $slug;
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
