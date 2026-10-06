<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\DeliveryRoute;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StoreDeliveryRouteController extends ApiController
{
    use ResolvesManagementContext;

    /**
     * Fees travel in kobo (integers) — the storefront checkout divides by 100
     * when it charges shipping. The SPA form shows naira and converts.
     */
    public function store(Request $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $data = $this->validated($request);

        $route = DB::transaction(function () use ($store, $data) {
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

        Log::info('business.delivery_route.created', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
            'route_id' => $route->id,
        ]);

        return $this->ok(['delivery_route' => $this->payload($route)], 'Delivery route added successfully.', 201);
    }

    /**
     * `{route}` is resolved through the store relation, so a route belonging
     * to another store (or business) is a 404 rather than an edit target —
     * the legacy update only compared ids and leaked the difference at times.
     */
    public function update(Request $request, Store $store, int $route): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $deliveryRoute = $store->deliveryRoutes()->whereKey($route)->firstOrFail();
        $data = $this->validated($request);

        $deliveryRoute->update([
            'country' => $data['country'],
            'state' => $data['state'],
            // Same NOT NULL constraint as create(): store '' rather than null.
            'area' => $data['area'] ?? '',
            'fee' => (int) $data['fee'],
            'delivery_days' => (int) $data['delivery_days'],
            // An omitted checkbox keeps the current state instead of silently
            // deactivating the route the way the legacy form did.
            'active' => $data['active'] ?? $deliveryRoute->active,
        ]);

        Log::info('business.delivery_route.updated', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
            'route_id' => $deliveryRoute->id,
        ]);

        return $this->ok(['delivery_route' => $this->payload($deliveryRoute->fresh())], 'Delivery route updated successfully.');
    }

    public function destroy(Request $request, Store $store, int $route): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $deliveryRoute = $store->deliveryRoutes()->whereKey($route)->firstOrFail();
        $deliveryRoute->delete();

        Log::info('business.delivery_route.deleted', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
            'route_id' => $deliveryRoute->id,
        ]);

        return $this->ok([], 'Delivery route deleted successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'country' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'max:255'],
            'area' => ['nullable', 'string', 'max:255'],
            'fee' => ['required', 'integer', 'min:0'],
            'delivery_days' => ['required', 'integer', 'min:1'],
            'active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DeliveryRoute $route): array
    {
        return [
            'id' => $route->id,
            'country' => $route->country,
            'state' => $route->state,
            'area' => $route->area,
            'fee' => (int) $route->fee,
            'delivery_days' => (int) $route->delivery_days,
            'active' => (bool) $route->active,
        ];
    }
}
