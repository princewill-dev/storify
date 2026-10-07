<?php

namespace App\Http\Resources\Management;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-02 — a store row as the onboarding list, the create echo and the
 * finalize screen render it.
 *
 * Field names, types and order are the controller's inline `payload()` moved
 * verbatim; tests assert this payload. `customers_count` takes the caller's
 * computed distinct-buyer count — the list passes the page's per-store count,
 * create passes 0 and finalize passes the store's count — and falls back to
 * the legacy `customers_count` attribute, which no store ever had, exactly as
 * payload() did.
 */
final class StoreOnboardingResource extends JsonResource
{
    public function __construct(Store $store, private readonly ?int $customersCount = null)
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
            'pos_enabled' => (bool) $store->pos_enabled,
            'balance' => (int) $store->balance,
            'payment_mode' => $store->payment_mode,
            'description' => $store->description,
            'location' => $store->physical_address ?: $store->address,
            'address' => $store->address,
            'physical_address' => $store->physical_address,
            'support_email' => $store->support_email,
            'support_phone' => $store->support_phone,
            'instagram_url' => $store->instagram_url,
            'facebook_url' => $store->facebook_url,
            'twitter_url' => $store->twitter_url,
            'tiktok_url' => $store->tiktok_url,
            'currency_id' => $store->currency_id,
            'views' => (int) $store->views,
            'logo_url' => $store->logoUrl(),
            'storefront_url' => self::storefrontUrl($store),
            'products_count' => $store->products_count ?? null,
            'categories_count' => $store->categories_count ?? null,
            'orders_count' => $store->orders_count ?? null,
            'customers_count' => $this->customersCount ?? ($store->customers_count ?? null),
            'created_at' => $store->created_at?->toISOString(),
        ];
    }

    /**
     * Same rule the legacy links used: local dev serves storefronts from the
     * root domain, everything else from {slug}.{main_domain}.
     *
     * Shared by the store payload and the finalize screen's top-level
     * `storefront_url` so the two can never disagree.
     */
    public static function storefrontUrl(Store $store): ?string
    {
        if (! $store->has_website || ! $store->slug) {
            return null;
        }

        if (app()->environment('local')) {
            return url($store->slug);
        }

        $domain = config('app.main_domain', parse_url((string) config('app.url'), PHP_URL_HOST));

        return 'https://'.$store->slug.'.'.$domain;
    }
}
