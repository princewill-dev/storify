<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Storefront\Concerns\ResolvesStorefrontContext;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The storefront's lifetime view counters.
 *
 * `stores.views` and `products.views` are what the business's web-metrics tiles
 * read. Nothing else writes them: the legacy Blade application incremented them
 * in the controller that rendered the page, and the SPA that replaced it never
 * had an equivalent — so both counters sat at zero while real people shopped.
 *
 * ## Why a report from the page, and not the catalog's own GET
 *
 * The catalog endpoints are fetches, not views. Counting `home` would count the
 * layout's boot request (which every route makes, including a product page),
 * every retry after a failed load, and any future prefetch or API consumer.
 * Only the page knows that a person was actually shown it, so the storefront
 * reports one view per navigation.
 *
 * ## Nothing here is a source of truth
 *
 * A view is an analytic, never a ledger. The worst a repeated or spoofed report
 * can do is overstate a marketing figure, so the route is throttled to keep one
 * client from doing that at scale, and the storefront sends it fire-and-forget:
 * a failed report must never surface on the shop.
 */
final class StoreViewController extends ApiController
{
    use ResolvesStorefrontContext;

    public function store(Request $request, string $store): JsonResponse
    {
        $store = $this->resolveStore($store);

        $data = $request->validate([
            'kind' => ['required', 'string', 'in:store,product'],
            'product' => ['required_if:kind,product', 'nullable', 'string', 'max:191'],
        ]);

        if ($data['kind'] === 'store') {
            $store->increment('views');

            return $this->ok(['kind' => 'store'], null, 202);
        }

        // The same lookup the detail page uses, including the trailing segment
        // of a `slug-code` URL, so a view is counted against the product the
        // visitor was actually shown rather than the URL string.
        $slugOrCode = (string) $data['product'];
        $code = str_contains($slugOrCode, '-')
            ? substr($slugOrCode, strrpos($slugOrCode, '-') + 1)
            : $slugOrCode;

        $product = Product::query()
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->where('slug', $slugOrCode)
                ->orWhere('product_code', $slugOrCode)
                ->orWhere('product_code', $code))
            ->firstOrFail();

        $product->increment('views');

        return $this->ok(['kind' => 'product'], null, 202);
    }
}
