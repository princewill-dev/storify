<?php

namespace App\Http\Resources\Management\StoreSettings;

use App\Http\Resources\Management\DeliveryRoute\DeliveryRouteResource;
use App\Models\ServiceCharge;
use App\Models\Store;
use App\Models\StoreBank;
use App\Models\User;
use App\Support\SpaUrls;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * WS-04 — everything the settings workspace renders: the detail cards, service
 * charges, delivery routes, assigned/available staff, read-only bank accounts,
 * POS session state and storefront status.
 * StoreSettingsController::settingsPayload() moved here key-for-key and in the
 * same order; exact-JSON consumers assert that order, so the top-level keys
 * may not be sorted or renamed.
 *
 * The store card, charge rows and staff rows use the resources beside this
 * class. Delivery-route rows reuse the WS-04 route resource — the two maps
 * were byte-identical (and `fee` stays integer kobo in both), so a second copy
 * would only be able to drift. The bank, POS and storefront blocks stay inline:
 * they are rendered only here, so a dedicated class each would be a wrapper
 * with a single call site and no shape to share.
 *
 * The available-staff collection arrives already queried — this class shapes
 * rows and never queries; App\Repositories\Management\StoreSettingsRepository
 * owns that (business-scoped) query.
 *
 * @property Store $resource
 */
final class StoreSettingsResource extends JsonResource
{
    /**
     * @param  Collection<int, User>  $availableStaff
     */
    public function __construct(Store $store, private readonly Collection $availableStaff)
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
        $session = $store->activePosSession;

        return [
            'store' => (new StoreResource($store))->resolve($request),
            'service_charges' => $store->serviceCharges
                ->sortBy('name')
                ->values()
                ->map(fn (ServiceCharge $charge) => (new ServiceChargeResource($charge))->resolve($request))
                ->all(),
            'delivery_routes' => $store->deliveryRoutes
                ->sortBy('state')
                ->values()
                ->map(fn ($route) => (new DeliveryRouteResource($route))->resolve($request))
                ->all(),
            'staff' => [
                'assigned' => $store->assignedStaff
                    ->map(fn (User $member) => (new StaffResource($member))->resolve($request))
                    ->values()
                    ->all(),
                'available' => $this->availableStaff
                    ->map(fn (User $member) => (new StaffResource($member))->resolve($request))
                    ->values()
                    ->all(),
            ],
            'banks' => $store->assignedBanks->map(fn (StoreBank $bank) => [
                'id' => $bank->id,
                'bank_name' => $bank->bank_name,
                'account_name' => $bank->account_name,
                // Read-only: the masked accessor is the only form exposed, so
                // the full account number never enters this payload.
                'masked_account_number' => $bank->masked_account_number,
                'is_primary' => (bool) $bank->is_primary,
                'is_verified' => (bool) $bank->is_verified,
            ])->values()->all(),
            'pos' => [
                'enabled' => (bool) $store->pos_enabled,
                'active_session' => $session ? [
                    'id' => $session->id,
                    'session_code' => $session->session_code,
                    'opened_by' => $session->staff?->name,
                    'opened_at' => $session->opened_at?->toISOString(),
                    'opening_balance' => (int) $session->opening_balance,
                ] : null,
            ],
            'storefront' => [
                'enabled' => (bool) $store->has_website,
                // Built through SpaUrls so the storefront domain stays in one
                // place — it is not the platform domain this app is served on.
                'url' => $store->has_website && $store->slug
                    ? SpaUrls::storefront($store->slug)
                    : null,
            ],
        ];
    }
}
