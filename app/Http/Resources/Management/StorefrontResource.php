<?php

namespace App\Http\Resources\Management;

use App\Models\DeliveryRoute;
use App\Models\Store;
use App\Support\SpaUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-05 — the store row the overview, detail and enable responses render.
 *
 * This is the controller's private `payload()` array, kept key-for-key and in
 * the same order: exact-JSON consumers assert the field order, so the keys may
 * not be sorted or renamed. `nationwide_delivery` is null when the store has
 * no "All States" route, exactly as the optional method argument was.
 */
final class StorefrontResource extends JsonResource
{
    public function __construct(Store $store, private readonly ?DeliveryRoute $nationwide = null)
    {
        parent::__construct($store);
    }

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
            'store_type' => $store->store_type,
            'has_website' => (bool) $store->has_website,
            'storefront_url' => $store->has_website && $store->slug ? self::urlFor($store->slug) : null,
            'logo_url' => $store->logoUrl(),
            'description' => $store->description,
            'nationwide_delivery' => $this->nationwide === null ? null : [
                'id' => $this->nationwide->id,
                'state' => $this->nationwide->state,
                'country' => $this->nationwide->country,
                'fee' => (int) $this->nationwide->fee,
                'delivery_days' => (int) $this->nationwide->delivery_days,
                'active' => (bool) $this->nationwide->active,
            ],
        ];
    }

    /**
     * Same rule the legacy links used: local dev serves storefronts from the
     * root domain, everything else from {slug}.{main_domain}.
     *
     * Shared by the payload and the controller's slug preview / enable message
     * so the three can never disagree.
     */
    public static function urlFor(string $slug): string
    {
        return SpaUrls::storefront($slug);
    }
}
