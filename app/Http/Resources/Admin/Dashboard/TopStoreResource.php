<?php

namespace App\Http\Resources\Admin\Dashboard;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * WS7 — one row of the store table: every non-deleted store with its
 * today/MTD pulse, sorted by MTD revenue.
 *
 * `$store->status` stays the raw column string (Store does not cast it), and
 * the aggregate aliases (`revenue_today`, `revenue_mtd`, `orders_today`,
 * `orders_count`, `revenue`, `products_count`) come from the repository's
 * withCount/withSum/selectSub; the null-coalesce and casts are the ones the
 * controller carried when the row was built inline.
 *
 * @property-read Store $resource
 */
final class TopStoreResource extends JsonResource
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
            'store_id' => $store->store_id,
            'name' => $store->name,
            'status' => $store->status,
            'owner' => $store->user?->name,
            'business' => $store->business?->name,
            'revenue_today' => (float) ($store->revenue_today ?? 0),
            'revenue_mtd' => (float) ($store->revenue_mtd ?? 0),
            'orders_today' => (int) ($store->orders_today ?? 0),
            'orders_count' => (int) ($store->orders_count ?? 0),
            'revenue' => (float) ($store->revenue ?? 0),
            'products_count' => (int) ($store->products_count ?? 0),
            'pos_status' => $store->activePosSession ? 'open' : 'closed',
            'last_order_at' => $store->last_order_at ? Carbon::parse($store->last_order_at)->toISOString() : null,
        ];
    }
}
