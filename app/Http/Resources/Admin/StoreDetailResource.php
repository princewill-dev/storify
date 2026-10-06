<?php

namespace App\Http\Resources\Admin;

use App\Models\Category;
use App\Models\Pack;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * WS-6 (admin console) — the store detail console payload.
 *
 * Everything the legacy detail page answered on top of the directory row: the
 * Store Info card (description, support contacts, address, socials, logo), the
 * Business & Owner card, computed metric tiles, and the Products / Categories /
 * Packs panels.
 *
 * The panel rows, the confirmed-transaction total and the order aggregates
 * arrive as the raw rows `StoreModerationRepository::detailBlocks()` read, so
 * response shaping issues no queries of its own. Legacy hard-coded the tiles to
 * zero ("Total amount earned", "Customers", "Sales"); they are real numbers
 * now.
 *
 * `stats.total_earned` is deliberately `round((float) $total, 2)` over the
 * stored amount — the exact expression the controller carried. It is not a
 * kobo conversion: routing it through `Naira::floatFromKobo()` would divide by
 * 100 and change a consumed payload, so the raw cast stays.
 */
final class StoreDetailResource extends StoreResource
{
    /**
     * @param  array{
     *     categories: Collection<int, Category>,
     *     recent_products: Collection<int, Product>,
     *     total_earned: mixed,
     *     customers_count: int,
     *     sales_count: int,
     *     packs: Collection<int, Pack>,
     *     packs_count: int
     * }  $blocks
     */
    public function __construct(Store $store, ?int $mainStoreId, private readonly array $blocks)
    {
        parent::__construct($store, $mainStoreId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Store $store */
        $store = $this->resource;

        $data = parent::toArray($request);

        // The console shows the business's owner when there is one; legacy
        // fell back to the store's own user row.
        $owner = $store->business?->owner ?? $store->user;

        $data['description'] = $store->description;
        $data['support_email'] = $store->support_email;
        $data['support_phone'] = $store->support_phone;
        $data['address'] = $store->address;
        $data['socials'] = [
            'instagram' => $store->instagram_url,
            'facebook' => $store->facebook_url,
            'twitter' => $store->twitter_url,
            'tiktok' => $store->tiktok_url,
        ];
        $data['payment_mode'] = $store->payment_mode;
        $data['views'] = (int) $store->views;
        $data['business_block'] = $store->business ? [
            'id' => $store->business->id,
            'name' => $store->business->name,
            'business_code' => $store->business->business_code,
        ] : null;
        $data['owner'] = $owner ? [
            'id' => $owner->id,
            'name' => $owner->name,
            'email' => $owner->email,
            'phone' => $owner->phone,
        ] : null;
        $data['stats'] = [
            'total_earned' => round((float) $this->blocks['total_earned'], 2),
            'customers_count' => $this->blocks['customers_count'],
            'products_count' => (int) ($store->products_count ?? 0),
            'sales_count' => $this->blocks['sales_count'],
        ];
        $data['categories'] = $this->blocks['categories']->map(fn (Category $category) => [
            'id' => $category->id,
            'name' => $category->name,
            'status' => $category->status,
        ])->values()->all();
        $data['categories_count'] = $this->blocks['categories']->count();
        $data['recent_products'] = $this->blocks['recent_products']->map(fn (Product $product) => [
            'id' => $product->id,
            'product_code' => $product->product_code,
            'name' => $product->name,
            'amount' => $product->amount !== null ? (float) $product->amount : null,
            'status' => $product->status,
        ])->values()->all();
        // Store has no packs() relation; the panel shows the rows the
        // repository queried the table for, the way the legacy controller did.
        $data['packs'] = $this->blocks['packs']->map(fn (Pack $pack) => [
            'id' => $pack->id,
            'pack_code' => $pack->pack_code,
            'name' => $pack->name,
            'amount' => $pack->amount !== null ? (float) $pack->amount : null,
            'status' => $pack->status,
        ])->values()->all();
        $data['packs_count'] = $this->blocks['packs_count'];
        $data['updated_at'] = $store->updated_at?->toISOString();

        return $data;
    }
}
