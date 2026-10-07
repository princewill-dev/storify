<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Http\Requests\Management\DeliveryRoute\DeliveryRouteRequest;
use App\Http\Resources\Management\DeliveryRoute\DeliveryRouteResource;
use App\Models\DeliveryRoute;
use App\Models\Store;
use App\Services\Management\DeliveryRouteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WS-04 — a store's delivery routes.
 *
 * Layering: the HTTP shape (status codes, message strings, the envelope and
 * the audit log lines) stays here; the create/edit payload rules live in
 * App\Http\Requests\Management\DeliveryRoute\DeliveryRouteRequest, the row
 * shape in App\Http\Resources\Management\DeliveryRoute\DeliveryRouteResource,
 * and the one write that spans two statements — the insert plus the
 * `business_id` stamp, which the transaction makes atomic — in
 * App\Services\Management\DeliveryRouteService. No repository: this surface
 * has no list, no filter and no aggregate, and the two scoped `{route}`
 * lookups are a single relation call each, which a repository method would
 * only wrap.
 *
 * Two things deliberately stay in this body, both so their place in the
 * refusal order is unchanged:
 *  - the store guard is ResolvesManagementContext::authorizeStore() called
 *    first, not middleware and not FormRequest::authorize();
 *  - `{route}` is resolved through the store relation, so a route belonging
 *    to another store (or business) is a 404 rather than an edit target —
 *    the legacy update only compared ids and leaked the difference at times.
 */
class StoreDeliveryRouteController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly DeliveryRouteService $service,
    ) {}

    /**
     * Fees travel in kobo (integers) — the storefront checkout divides by 100
     * when it charges shipping. The SPA form shows naira and converts; `fee`
     * arrives here already kobo and nothing on this endpoint converts money.
     */
    public function store(DeliveryRouteRequest $request, Store $store): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $route = $this->service->createForStore($store, $request->validated());

        Log::info('business.delivery_route.created', [
            'user_id' => $this->user($request)->id,
            'store_id' => $store->id,
            'route_id' => $route->id,
        ]);

        return $this->ok(['delivery_route' => $this->payload($route)], 'Delivery route added successfully.', 201);
    }

    public function update(DeliveryRouteRequest $request, Store $store, int $route): JsonResponse
    {
        $this->authorizeStore($request, $store);

        $deliveryRoute = $store->deliveryRoutes()->whereKey($route)->firstOrFail();
        $data = $request->validated();

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
     * Thin seam so the response sites read as they did before the extraction;
     * the row shape lives in DeliveryRouteResource.
     *
     * @return array<string, mixed>
     */
    private function payload(DeliveryRoute $route): array
    {
        return DeliveryRouteResource::make($route)->resolve();
    }
}
