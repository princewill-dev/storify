<?php

namespace App\Http\Resources\Management\StoreWebMetrics;

use App\Models\Currency;
use App\Models\Store;
use App\Support\SpaUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-35 — the store block the web-metrics page header draws (identity,
 * storefront link, currency).
 *
 * Field names, types and order are the controller's inline `storePayload()`
 * moved verbatim; the endpoint's tests assert this shape. The storefront URL
 * comes from SpaUrls, so it follows the storefront domain (storify.buzz) and
 * the local-development branch, not this app's own main_domain.
 *
 * @property Store $resource
 */
final class StoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Store $store */
        $store = $this->resource;

        return [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'slug' => $store->slug,
            'status' => $store->status,
            'has_website' => (bool) $store->has_website,
            'logo_url' => $store->logo_path ? asset('storage/'.$store->logo_path) : null,
            'currency_symbol' => $store->currency_id
                ? Currency::whereKey($store->currency_id)->value('symbol')
                : null,
            'store_url' => self::storefrontUrl($store),
        ];
    }

    private static function storefrontUrl(Store $store): ?string
    {
        if (! $store->has_website || ! $store->slug) {
            return null;
        }

        return SpaUrls::storefront($store->slug);
    }
}
