<?php

namespace App\Repositories\Management;

use App\Models\Store;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reads for the storefront settings workspace and the public content
 * endpoints.
 *
 * `settingsFor()` returns an unsaved model when nothing has been saved yet.
 * That keeps the GET a GET: opening the tab must not write a row, and the
 * resource's effective values are the same either way.
 */
final class StorefrontSettingsRepository
{
    public function settingsFor(Store $store): StorefrontSetting
    {
        return $store->storefrontSettings()->first()
            ?? new StorefrontSetting([
                'store_id' => $store->id,
                'business_id' => $store->business_id,
                'template_key' => 'default',
            ]);
    }

    /**
     * @return Collection<int, StorefrontPage>
     */
    public function pagesFor(Store $store): Collection
    {
        return $store->storefrontPages()->orderBy('title')->get();
    }

    /**
     * A page belonging to this store, or 404.
     *
     * Scoped through the relation rather than resolved by id, so an id from
     * another store is indistinguishable from one that does not exist.
     */
    public function pageFor(Store $store, int $pageId): StorefrontPage
    {
        return $store->storefrontPages()->whereKey($pageId)->firstOrFail();
    }

    /**
     * @return Collection<int, StorefrontPage>
     */
    public function footerPages(Store $store): Collection
    {
        return $store->storefrontPages()
            ->published()
            ->where('show_in_footer', true)
            ->orderBy('title')
            ->get();
    }

    public function publishedPage(Store $store, string $slug): ?StorefrontPage
    {
        return $store->storefrontPages()
            ->published()
            ->where('slug', $slug)
            ->first();
    }

    /**
     * @return Collection<int, StorefrontPage>
     */
    public function publishedPages(Store $store): Collection
    {
        return $store->storefrontPages()->published()->orderBy('title')->get();
    }
}
