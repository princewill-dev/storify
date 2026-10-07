<?php

namespace App\Services\Management;

use App\Models\DeliveryRoute;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * WS-04 — the store delivery-route write that spans more than one statement.
 *
 * Creating a route is an insert plus the `business_id` stamp (the column is
 * not fillable on DeliveryRoute, so the stamp is a second write), and the two
 * must be atomic — that transaction boundary is the whole reason this lives
 * here rather than on the controller. Update and delete are single-row
 * persists with no workflow, so they stay on the controller; routing them
 * through this service would be indirection with no benefit.
 *
 * Fees are integer kobo end to end on this endpoint — the SPA form converts
 * the naira it shows — so nothing here converts money.
 */
final class DeliveryRouteService
{
    /**
     * @param  array<string, mixed>  $data  validated by DeliveryRouteRequest
     */
    public function createForStore(Store $store, array $data): DeliveryRoute
    {
        return DB::transaction(function () use ($store, $data) {
            $route = $store->deliveryRoutes()->create([
                'country' => $data['country'],
                'state' => $data['state'],
                // delivery_routes.area is NOT NULL with no default and MySQL
                // runs strict, so an omitted area must persist as '' — a null
                // insert would 500 instead of saving the route.
                'area' => $data['area'] ?? '',
                'fee' => (int) $data['fee'],
                'delivery_days' => (int) $data['delivery_days'],
                'active' => $data['active'] ?? true,
            ]);

            // business_id is not fillable on DeliveryRoute, so stamp it here
            // rather than leaving the column null on new routes.
            $route->forceFill(['business_id' => $store->business_id])->save();

            return $route;
        });
    }
}
