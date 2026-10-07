<?php

namespace App\Http\Resources\Home;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One featured store on the marketing home payload.
 *
 * Field names, types and order match the payload the controller built inline.
 * `logo_url` resolves `logo_path` on the public disk; the admin console's
 * store resource carries the moderation and storefront fields, none of which
 * belong on this public card.
 *
 * @property-read Store $resource
 */
final class StoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Store $store */
        $store = $this->resource;

        return [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'description' => $store->description,
            'logo_url' => $store->logo_path ? asset('storage/'.$store->logo_path) : null,
        ];
    }
}
