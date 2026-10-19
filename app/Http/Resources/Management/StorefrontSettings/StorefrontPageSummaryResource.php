<?php

namespace App\Http\Resources\Management\StorefrontSettings;

use App\Models\StorefrontPage;
use App\Support\Storefront\StorefrontPages;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A page row for the management list: no body, because the list never renders
 * one and the editor loads it separately.
 *
 * @mixin StorefrontPage
 */
class StorefrontPageSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'path' => StorefrontPages::path($this->slug),
            'is_published' => (bool) $this->is_published,
            'show_in_footer' => (bool) $this->show_in_footer,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
