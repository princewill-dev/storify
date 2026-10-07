<?php

namespace App\Http\Resources\Management\StoreSettings;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-04 — the store detail card rendered by the settings workspace and echoed
 * by the update response: StoreSettingsController::storePayload() moved
 * verbatim.
 *
 * Field names, types and order are frozen — exact-JSON consumers assert them,
 * so the keys may not be sorted or renamed. `business` and `businessType` are
 * read lazily here, exactly as the array read them (the settings load only
 * eager-loads businessType), and `logo_url` keeps the asset('storage/...')
 * prefix.
 *
 * @property Store $resource
 */
final class StoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $store = $this->resource;

        return [
            'id' => $store->id,
            'store_id' => $store->store_id,
            'name' => $store->name,
            'slug' => $store->slug,
            'status' => $store->status,
            'store_type' => $store->store_type,
            'has_website' => (bool) $store->has_website,
            'pos_enabled' => (bool) $store->pos_enabled,
            'description' => $store->description,
            'support_email' => $store->support_email,
            'support_phone' => $store->support_phone,
            'address' => $store->address,
            'instagram_url' => $store->instagram_url,
            'facebook_url' => $store->facebook_url,
            'twitter_url' => $store->twitter_url,
            'tiktok_url' => $store->tiktok_url,
            'logo_url' => $store->logo_path ? asset('storage/'.$store->logo_path) : null,
            'business' => [
                'id' => $store->business_id,
                'name' => $store->business?->name,
                'type' => $store->businessType?->name,
            ],
            'created_at' => $store->created_at?->toISOString(),
        ];
    }
}
