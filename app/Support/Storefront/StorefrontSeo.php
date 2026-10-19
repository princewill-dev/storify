<?php

namespace App\Support\Storefront;

use App\Models\Store;
use App\Models\StorefrontSetting;
use App\Support\SpaUrls;

/**
 * The metadata the public storefront and its crawler-facing Worker both read.
 *
 * Every field falls back to something the store already has, so a business
 * that never opens Storefront settings still gets a sensible title, description
 * and image instead of an empty social card.
 *
 * The description is plain text, not HTML: it is emitted as a meta `content`
 * attribute and shown as an excerpt by social platforms.
 */
final class StorefrontSeo
{
    /**
     * @return array{name: string, title: string, description: string|null, image_url: string|null, url: string, type: string}
     */
    public static function forStore(Store $store, ?StorefrontSetting $settings = null): array
    {
        $settings ??= $store->storefrontSettings;

        $description = $settings?->meta_description
            ?: StorefrontPages::excerpt($store->description);

        return [
            'name' => $store->name,
            'title' => $settings?->site_title ?: $store->name,
            'description' => $description !== '' ? $description : null,
            'image_url' => $settings?->og_image_path
                ? asset('storage/'.$settings->og_image_path)
                : $store->logoUrl(),
            'url' => SpaUrls::storefront($store->slug),
            'type' => 'website',
        ];
    }

    public static function imageUrl(?string $path): ?string
    {
        return $path ? asset('storage/'.$path) : null;
    }
}
