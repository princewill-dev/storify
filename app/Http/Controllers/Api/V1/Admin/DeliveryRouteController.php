<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\DeliveryRouteRequest;
use App\Http\Requests\Admin\ListDeliveryRoutesRequest;
use App\Http\Resources\Admin\DeliveryRouteLookupsResource;
use App\Http\Resources\Admin\DeliveryRouteResource;
use App\Models\DeliveryRoute;
use App\Repositories\Admin\DeliveryRouteRepository;
use App\Support\Money\Naira;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * WS-17 — platform-wide delivery routes (checkout configuration).
 *
 * Legacy `Admin\DeliveryRouteController` rebuilt against the API. Admin rows
 * stay platform-wide (`store_id` NULL) exactly as legacy wrote them, so the
 * screen only reads and writes `store_id IS NULL` routes: store-scoped routes
 * belong to the businesses that own them and are managed elsewhere. This is
 * the roadmap's scope decision (a) — admin manages the global defaults the
 * checkout fallback resolves to; the storefront reader that prefers a store's
 * own route before falling back to these defaults lands on the storefront
 * side. Disabling a route here removes it from every consumer, because
 * delivery-option readers filter `active = 1`.
 *
 * Legacy defects fixed rather than cloned:
 *  - Legacy hard-deleted with no usage guard, leaving historical orders with a
 *    dangling `delivery_route_id`. Deletion is now refused — with an
 *    explanatory error — while any order (including soft-deleted ones), order
 *    delivery record or saved address references the route. Disable it
 *    instead: that hides it from checkout without rewriting history.
 *  - The legacy edit modal pre-filled the fee as `(int) ($fee / 100)`, so a
 *    fee with a kobo remainder silently truncated on re-save. `fee_ngn` is
 *    serialised to exact 2dp from the integer kobo and the validator accepts
 *    up to 2 decimal places, so a 123457 kobo route round-trips as 1234.57.
 *  - The legacy form was free text only, so "Ikeja" and "Ikeja City" became
 *    different areas. `lookups()` feeds a state picklist and per-state area
 *    suggestions; the form still accepts unlisted values.
 *  - `sort` is whitelisted before it reaches `orderBy` (same class of bug as
 *    the legacy order-index sort injection).
 *
 * Money stays integer kobo end to end — the NGN the admin types is parsed to
 * kobo and `fee` (kobo) is what checkout consumes. The parse rides
 * `Naira::koboFromDecimalOrFloat()`: its exact-decimal branch covers
 * everything `decimal:0,2` admits and its float fallback matches the old
 * inline float normalisation, whereas `koboFromStrict()` would zero
 * trailing-point forms like `12.`/`.5` that this validator accepts. `fee_ngn`
 * renders through `Naira::decimalFromKobo()`.
 *
 * The controller keeps the HTTP shape only — status codes, message strings,
 * the envelope and pagination meta. Validation lives in the Admin FormRequests
 * (`ListDeliveryRoutesRequest` for the list filters, `DeliveryRouteRequest`
 * for the shared create/edit payload), the list with its unfiltered summary
 * and the delete-usage counts in `DeliveryRouteRepository`, and response
 * shaping in `DeliveryRouteResource` / `DeliveryRouteLookupsResource`. The
 * platform-admin guard and the platform-scope 404 deliberately stay here so
 * their order is unchanged. The writes are single-table persists audited with
 * a log line, so no service layer is introduced.
 */
class DeliveryRouteController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly DeliveryRouteRepository $routes,
    ) {}

    public function index(ListDeliveryRoutesRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $routes = $this->routes->paginateForAdmin($request->validated());

        return $this->ok(
            [
                'routes' => $routes->getCollection()->map(fn (DeliveryRoute $route) => $this->payload($route))->all(),
                // Unfiltered totals so the screen's status pills stay stable
                // while a search narrows the table.
                'summary' => $this->routes->summary(),
            ],
            null,
            200,
            $this->paginationMeta($routes),
        );
    }

    /**
     * The Nigeria state/area picklist for the route form. The form remains
     * free text (legacy stored any country/state/area string); these are
     * suggestions that keep area names consistent.
     */
    public function lookups(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->ok((new DeliveryRouteLookupsResource)->resolve());
    }

    public function store(DeliveryRouteRequest $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();

        $route = DeliveryRoute::create([
            'store_id' => null,
            'country' => $data['country'],
            'state' => $data['state'],
            'area' => $data['area'],
            // NGN as typed → integer kobo; the class note records the
            // contract choice.
            'fee' => Naira::koboFromDecimalOrFloat($data['fee']),
            'delivery_days' => $data['delivery_days'],
            'active' => $data['active'] ?? true,
        ]);

        Log::info('api.admin.delivery_route_created', [
            'actor_user_id' => $request->user()?->id,
            'delivery_route_id' => $route->id,
        ]);

        return $this->ok(['route' => $this->payload($route)], 'Delivery route created.', 201);
    }

    public function update(DeliveryRouteRequest $request, DeliveryRoute $deliveryRoute): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->guardPlatformRoute($deliveryRoute);

        $data = $request->validated();

        $deliveryRoute->update([
            'country' => $data['country'],
            'state' => $data['state'],
            'area' => $data['area'],
            'fee' => Naira::koboFromDecimalOrFloat($data['fee']),
            'delivery_days' => $data['delivery_days'],
            'active' => $data['active'] ?? $deliveryRoute->active,
        ]);

        Log::info('api.admin.delivery_route_updated', [
            'actor_user_id' => $request->user()?->id,
            'delivery_route_id' => $deliveryRoute->id,
        ]);

        return $this->ok(['route' => $this->payload($deliveryRoute->fresh())], 'Delivery route updated.');
    }

    public function toggle(Request $request, DeliveryRoute $deliveryRoute): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->guardPlatformRoute($deliveryRoute);

        $deliveryRoute->update(['active' => ! $deliveryRoute->active]);

        Log::info('api.admin.delivery_route_toggled', [
            'actor_user_id' => $request->user()?->id,
            'delivery_route_id' => $deliveryRoute->id,
            'active' => (bool) $deliveryRoute->active,
        ]);

        return $this->ok(['route' => $this->payload($deliveryRoute->fresh())], 'Delivery route status updated.');
    }

    public function destroy(Request $request, DeliveryRoute $deliveryRoute): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->guardPlatformRoute($deliveryRoute);

        if (($usage = $this->usageSummary($deliveryRoute)) !== null) {
            return $this->error(
                "This route is referenced by {$usage}. Disable it instead of deleting so historical orders and saved addresses keep their delivery details.",
                422,
            );
        }

        $deliveryRoute->delete();

        Log::info('api.admin.delivery_route_deleted', [
            'actor_user_id' => $request->user()?->id,
            'delivery_route_id' => $deliveryRoute->id,
        ]);

        return $this->ok([], 'Delivery route deleted.');
    }

    /**
     * Only platform-wide rows are addressable; a store-scoped id hidden in a
     * request resolves as not found rather than being editable from here.
     */
    private function guardPlatformRoute(DeliveryRoute $route): void
    {
        abort_unless($route->store_id === null, 404);
    }

    /**
     * A human list of what references the route, or null when nothing does.
     * Soft-deleted orders still hold the id, so they count too.
     */
    private function usageSummary(DeliveryRoute $route): ?string
    {
        $counts = $this->routes->usageCounts($route);

        $labels = [
            'orders' => 'order',
            'delivery_records' => 'delivery record',
            'saved_addresses' => 'saved address',
        ];

        $parts = [];

        foreach ($labels as $key => $label) {
            $count = $counts[$key];

            if ($count > 0) {
                $parts[] = $count.' '.$label.($count === 1 ? '' : 's');
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * The row shape, kept as a thin seam so the response sites read as they
     * did before the extraction; the fields live in DeliveryRouteResource.
     *
     * @return array<string, mixed>
     */
    private function payload(DeliveryRoute $route): array
    {
        return DeliveryRouteResource::make($route)->resolve();
    }
}
