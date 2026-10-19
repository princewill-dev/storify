<?php

namespace App\Http\Controllers\Api\V1\Storefront;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Storefront\Concerns\ResolvesStorefrontContext;
use App\Models\StorefrontPage;
use App\Repositories\Management\StorefrontSettingsRepository;
use App\Support\Storefront\StorefrontPages;
use App\Support\Storefront\StorefrontSeo;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * Public storefront content: the crawler-facing metadata and owner-written
 * pages.
 *
 * Both endpoints go through ResolvesStorefrontContext, so an offline shop
 * answers the same `storefront_offline` 404 as every other public endpoint.
 * The Worker that injects the tags is built to fail open, which is what lets
 * this be a hard refusal rather than a special case.
 *
 * Drafts are never visible here. A draft URL is a 404 with a `page_not_found`
 * marker rather than a 403, so the SPA can show a real not-found state and a
 * visitor cannot tell a draft from a page that never existed.
 */
class ContentController extends ApiController
{
    use ResolvesStorefrontContext;

    public function __construct(
        private readonly StorefrontSettingsRepository $pages,
    ) {}

    public function seo(string $store): JsonResponse
    {
        $store = $this->resolveStore($store);
        $site = StorefrontSeo::forStore($store);

        return $this->ok([
            'site' => [
                'name' => $site['name'],
                'title' => $site['title'],
                'description' => $site['description'],
                'image' => $site['image_url'],
                'url' => $site['url'],
                'type' => $site['type'],
            ],
            'pages' => $this->pageSummaries($this->pages->publishedPages($store)),
        ]);
    }

    public function page(string $store, string $slug): JsonResponse
    {
        $store = $this->resolveStore($store);

        $page = $this->pages->publishedPage($store, $slug);

        if (! $page) {
            return $this->error('Page not found.', 404, ['page' => ['page_not_found']]);
        }

        return $this->ok([
            'title' => $page->title,
            'slug' => $page->slug,
            'path' => StorefrontPages::path($page->slug),
            'content' => $page->content,
            'updated_at' => $page->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * @param  Collection<int, StorefrontPage>  $pages
     * @return array<int, array{path: string, title: string, description: string}>
     */
    private function pageSummaries(Collection $pages): array
    {
        return $pages->map(fn (StorefrontPage $page) => [
            'path' => StorefrontPages::path($page->slug),
            'title' => $page->title,
            'description' => StorefrontPages::excerpt($page->content),
        ])->values()->all();
    }
}
