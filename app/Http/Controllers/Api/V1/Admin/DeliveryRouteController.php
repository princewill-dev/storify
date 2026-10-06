<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Data\Nigeria;
use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\DeliveryAddress;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\OrderDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

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
 * kobo without float arithmetic and `fee` (kobo) is what checkout consumes.
 */
class DeliveryRouteController extends ApiController
{
    use EnsuresPlatformAdmin;

    /**
     * Columns the index accepts as a sort target. Anything else is rejected
     * by the validator instead of being handed to `orderBy`.
     *
     * @var array<int, string>
     */
    private const SORTABLE = ['country', 'state', 'area', 'fee', 'delivery_days', 'active', 'created_at', 'updated_at'];

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Admin-managed routes are the platform-wide defaults; store-scoped
        // rows are deliberately invisible here.
        $base = DeliveryRoute::query()->whereNull('store_id');

        $query = (clone $base)
            ->when($filters['q'] ?? null, function ($query, $term) {
                $query->where(function ($query) use ($term) {
                    $query->where('country', 'like', "%{$term}%")
                        ->orWhere('state', 'like', "%{$term}%")
                        ->orWhere('area', 'like', "%{$term}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('active', $status === 'active'));

        // Legacy order was country → state → area; a chosen sort column leads
        // and the legacy reading order breaks ties.
        $sort = $filters['sort'] ?? null;

        if ($sort === null) {
            $query->orderBy('country')->orderBy('state')->orderBy('area');
        } else {
            $query->orderBy($sort, $filters['direction'] ?? 'asc');

            foreach (['country', 'state', 'area'] as $tieBreak) {
                if ($tieBreak !== $sort) {
                    $query->orderBy($tieBreak);
                }
            }
        }

        $routes = $query->orderBy('id')->paginate($filters['per_page'] ?? 20)->withQueryString();

        return $this->ok(
            [
                'routes' => $routes->getCollection()->map(fn (DeliveryRoute $route) => $this->payload($route))->all(),
                // Unfiltered totals so the screen's status pills stay stable
                // while a search narrows the table.
                'summary' => [
                    'total' => (clone $base)->count(),
                    'active' => (clone $base)->where('active', true)->count(),
                    'inactive' => (clone $base)->where('active', false)->count(),
                    'states' => (clone $base)->distinct()->count('state'),
                ],
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

        return $this->ok([
            'countries' => ['Nigeria'],
            'states' => array_values(Nigeria::states()),
            'areas_by_state' => $this->areasByState(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $this->validated($request);

        $route = DeliveryRoute::create([
            'store_id' => null,
            'country' => $data['country'],
            'state' => $data['state'],
            'area' => $data['area'],
            'fee' => $this->toKobo($data['fee']),
            'delivery_days' => $data['delivery_days'],
            'active' => $data['active'] ?? true,
        ]);

        Log::info('api.admin.delivery_route_created', [
            'actor_user_id' => $request->user()?->id,
            'delivery_route_id' => $route->id,
        ]);

        return $this->ok(['route' => $this->payload($route)], 'Delivery route created.', 201);
    }

    public function update(Request $request, DeliveryRoute $deliveryRoute): JsonResponse
    {
        $this->authorizePlatformAdmin();
        $this->guardPlatformRoute($deliveryRoute);

        $data = $this->validated($request);

        $deliveryRoute->update([
            'country' => $data['country'],
            'state' => $data['state'],
            'area' => $data['area'],
            'fee' => $this->toKobo($data['fee']),
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
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'country' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'area' => ['required', 'string', 'max:150'],
            // NGN as typed on the form (up to 2 decimal places); converted to
            // integer kobo on write. Legacy accepted whole naira only, which
            // is what truncated kobo remainders on edit.
            'fee' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100000000'],
            'delivery_days' => ['required', 'integer', 'min:1', 'max:60'],
            'active' => ['sometimes', 'boolean'],
        ]);
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
        $counts = [
            'order' => Order::withTrashed()->where('delivery_route_id', $route->id)->count(),
            'delivery record' => OrderDelivery::where('delivery_route_id', $route->id)->count(),
            'saved address' => DeliveryAddress::where('delivery_route_id', $route->id)->count(),
        ];

        $parts = [];

        foreach ($counts as $label => $count) {
            if ($count > 0) {
                $parts[] = $count.' '.$label.($count === 1 ? '' : 's');
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }

    /**
     * Parse submitted NGN into integer kobo. The value is normalised to a
     * 2dp string and split on the decimal point, so no float arithmetic ever
     * touches the stored amount (1234.57 → 123457, not 123400).
     */
    private function toKobo(int|float|string $ngn): int
    {
        $normalised = number_format((float) $ngn, 2, '.', '');
        [$units, $kobo] = explode('.', $normalised);

        return ((int) $units) * 100 + (int) $kobo;
    }

    /**
     * Exact NGN string for the edit form — integer arithmetic only, so a fee
     * of 123457 kobo renders as "1234.57" rather than being truncated.
     */
    private function toNgn(int $kobo): string
    {
        $sign = $kobo < 0 ? '-' : '';
        $kobo = abs($kobo);

        return $sign.intdiv($kobo, 100).'.'.str_pad((string) ($kobo % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function areasByState(): array
    {
        $areas = [];

        foreach (array_values(Nigeria::states()) as $state) {
            $cities = Nigeria::citiesByState($state);

            if ($cities === []) {
                // citiesByState keys the FCT with an en dash while states()
                // spells it with an em dash — try the alternate spelling.
                // One-way only: a two-entry str_replace swaps em→en and then
                // en→em over its own output, returning the input unchanged
                // and losing the FCT suggestions entirely.
                $alternate = str_contains($state, '—')
                    ? str_replace('—', '–', $state)
                    : str_replace('–', '—', $state);

                $cities = Nigeria::citiesByState($alternate);
            }

            if ($cities !== []) {
                $areas[$state] = array_values($cities);
            }
        }

        return $areas;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DeliveryRoute $route): array
    {
        return [
            'id' => $route->id,
            'store_id' => $route->store_id,
            'country' => $route->country,
            'state' => $route->state,
            'area' => $route->area,
            // kobo — the exact contract checkout consumes.
            'fee' => (int) $route->fee,
            // the same amount as NGN, safe to pre-fill a form with.
            'fee_ngn' => $this->toNgn((int) $route->fee),
            'delivery_days' => (int) $route->delivery_days,
            'active' => (bool) $route->active,
            'created_at' => $route->created_at?->toISOString(),
            'updated_at' => $route->updated_at?->toISOString(),
        ];
    }
}
