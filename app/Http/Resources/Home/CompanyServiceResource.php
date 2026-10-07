<?php

namespace App\Http\Resources\Home;

use App\Models\CompanyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One service card on the marketing home payload.
 *
 * Field names, types and order match the payload the controller built inline.
 * `image_url` resolves `background_image_path` on the public disk; the admin
 * console's own resource for this model carries extra console fields and an
 * absolute `page_url`, which this public payload has never exposed.
 *
 * @property-read CompanyService $resource
 */
final class CompanyServiceResource extends JsonResource
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
            'title' => $service->title,
            'description' => $service->description,
            'page_link' => $service->page_link,
            'image_url' => $service->background_image_path ? asset('storage/'.$service->background_image_path) : null,
        ];
    }
}
