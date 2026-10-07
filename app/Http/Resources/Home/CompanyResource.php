<?php

namespace App\Http\Resources\Home;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `company` block of the marketing home payload.
 *
 * Shaped from the singleton settings row, which may be absent — no row before
 * the first save, or the table itself missing, both of which the controller
 * guards before this resource is built. Every field keeps the original
 * config/asset fallback, and field names, types and order are the public
 * contract. The `seo` block is a nested array in the payload's own order.
 *
 * @property-read Setting|null $resource
 */
final class CompanyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Setting|null $setting */
        $setting = $this->resource;

        return [
            'name' => $setting?->company_name ?? config('app.name'),
            'description' => $setting?->company_description ?? null,
            'logo_url' => $setting?->company_logo_path ? asset('storage/'.$setting->company_logo_path) : asset('logo.png'),
            'favicon_url' => $setting?->company_favicon_path ? asset('storage/'.$setting->company_favicon_path) : asset('favicon.png'),
            'email' => $setting?->support_email ?? null,
            'phone' => $setting?->support_phone ?? null,
            'address' => $setting?->company_address ?? null,
            'seo' => [
                'title' => $setting?->og_title ?? config('app.name'),
                'description' => $setting?->og_description ?? null,
                'image' => $setting?->og_image_path ? asset('storage/'.$setting->og_image_path) : null,
                'url' => $setting?->og_url ?? url('/'),
                'type' => $setting?->og_type ?? 'website',
            ],
        ];
    }
}
