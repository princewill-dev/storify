<?php

namespace App\Http\Resources\Management\StorefrontSettings;

use App\Models\StorefrontPage;
use App\Support\Storefront\StorefrontPages;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full page, including the sanitized body the editor loads.
 *
 * @mixin StorefrontPage
 */
class StorefrontPageResource extends JsonResource
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
            'content' => $this->content,
            'is_published' => (bool) $this->is_published,
            'show_in_footer' => (bool) $this->show_in_footer,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
