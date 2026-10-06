<?php

namespace App\Http\Resources\Admin;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * WS-6 (admin console) — the store directory row.
 *
 * Legacy's scanning columns plus the enrichment the audit asked for: logo,
 * owner, business code, business type, main badge and shop link. Every key
 * the previous read-only endpoint returned is preserved so existing consumers
 * keep working.
 *
 * The platform main-store id arrives from the controller, which resolves it
 * once per request so a list of fifty stores does not re-query the settings
 * row for every row's "Main" badge.
 *
 * @property-read Store $resource
 */
class StoreResource extends JsonResource
{
    public function __construct(Store $store, private readonly ?int $mainStoreId = null)
    {
        parent::__construct($store);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Store $store */
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
            'balance' => (int) $store->balance,
            'business' => $store->business?->name,
            'business_id' => $store->business_id,
            'business_code' => $store->business?->business_code,
            'owner' => $store->user ? [
                'id' => $store->user->id,
                'name' => $store->user->name,
                'email' => $store->user->email,
                'phone' => $store->user->phone,
            ] : null,
            'logo_path' => $store->logo_path,
            'logo_url' => $store->logoUrl(),
            'ownership_type' => $store->ownershipType?->name,
            'business_type' => $store->businessType?->name,
            'ownership_type_id' => $store->ownership_type_id,
            'business_type_id' => $store->business_type_id,
            'is_main' => $this->mainStoreId !== null && (int) $store->id === $this->mainStoreId,
            'shop_url' => $store->has_website && $store->slug ? store_url($store->slug) : null,
            'products_count' => $store->products_count ?? null,
            'orders_count' => $store->orders_count ?? null,
            'created_at' => $store->created_at?->toISOString(),
        ];
    }
}
