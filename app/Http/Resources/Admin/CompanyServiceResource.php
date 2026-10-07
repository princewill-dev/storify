<?php

namespace App\Http\Resources\Admin;

use App\Models\CompanyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-18 (admin console) — the company-service row.
 *
 * Field names, types and order match the payload this endpoint has always
 * returned (exact-JSON assertions depend on them): `page_url` is the absolute
 * target for the Visit action (the SPA prefers its runtime HOME_URL when the
 * marketing site lives elsewhere) and `image_url` resolves the stored path on
 * the public disk.
 *
 * @property-read CompanyService $resource
 */
class CompanyServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CompanyService $service */
        $service = $this->resource;

        return [
            'id' => $service->id,
            'order' => (int) $service->order,
            'title' => $service->title,
            'description' => $service->description,
            'page_link' => $service->page_link,
            // Absolute target for the Visit action; the SPA prefers its
            // runtime HOME_URL when the marketing site lives elsewhere.
            'page_url' => $service->page_link ? url('/'.$service->page_link) : null,
            'image_url' => $service->background_image_path ? asset('storage/'.$service->background_image_path) : null,
            'has_image' => (bool) $service->background_image_path,
            'status' => $service->status,
            'created_at' => $service->created_at?->toISOString(),
            'updated_at' => $service->updated_at?->toISOString(),
        ];
    }
}
