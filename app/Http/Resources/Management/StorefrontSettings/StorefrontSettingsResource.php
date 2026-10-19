<?php

namespace App\Http\Resources\Management\StorefrontSettings;

use App\Models\Store;
use App\Models\StorefrontPage;
use App\Models\StorefrontSetting;
use App\Support\Storefront\StorefrontSeo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The whole Storefront settings workspace: the site metadata plus the page
 * list, in one response so opening the tab costs one request.
 */
class StorefrontSettingsResource extends JsonResource
{
    /**
     * @param  Collection<int, StorefrontPage>  $pages
     */
    public function __construct(
        private readonly Store $store,
        private readonly StorefrontSetting $settings,
        private readonly Collection $pages,
    ) {
        parent::__construct($settings);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $effective = StorefrontSeo::forStore($this->store, $this->settings);

        return [
            'site' => [
                'site_title' => $this->settings->site_title,
                'meta_description' => $this->settings->meta_description,
                'og_image_url' => StorefrontSeo::imageUrl($this->settings->og_image_path),
                'template_key' => $this->settings->template_key ?: 'default',
                'effective' => [
                    'title' => $effective['title'],
                    'description' => $effective['description'],
                    'image_url' => $effective['image_url'],
                    'url' => $effective['url'],
                ],
            ],
            'pages' => StorefrontPageSummaryResource::collection($this->pages)->resolve($request),
        ];
    }
}
