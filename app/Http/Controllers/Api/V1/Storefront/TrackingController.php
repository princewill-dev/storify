<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Storefront\Concerns\ResolvesStorefrontContext;
use App\Services\Plugins\PluginResolver;
use App\Support\Plugins\PluginTagRenderer;
use Illuminate\Http\JsonResponse;

/**
 * The plugin tags a storefront should run.
 *
 * Public and unauthenticated, because it is read by two callers that have no
 * session: the storefront SPA, and the Cloudflare Worker that rewrites the raw
 * HTML so verification meta tags are visible to crawlers that do not execute
 * JavaScript.
 *
 * It is a separate route rather than part of `home` because the Worker calls it
 * on every document request it rewrites. Dragging the product catalogue and
 * category tree along on each of those would be a large amount of work for one
 * cached response.
 *
 * The payload deliberately carries no ids, no `business_id` and no config
 * beyond what a page has to render — it is reachable by anyone who knows a
 * store slug.
 */
final class TrackingController extends ApiController
{
    use ResolvesStorefrontContext;

    public function show(string $store, PluginResolver $resolver): JsonResponse
    {
        $store = $this->resolveStore($store);

        $tags = PluginTagRenderer::render($resolver->forStore($store));

        return $this->ok([
            'store' => $store->slug,
            'tags' => $tags,
        ]);
    }
}
