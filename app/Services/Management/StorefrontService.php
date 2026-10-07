<?php

namespace App\Services\Management;

use App\Models\Store;
use App\Repositories\Management\StorefrontRepository;
use App\Support\Money\Naira;
use Illuminate\Support\Facades\DB;

/**
 * WS-05 — the storefront enable workflow.
 *
 * The store rename/re-slug and the nation-wide delivery route the storefront
 * checkout charges are one atomic write: a store must never go live pointing
 * at a route that failed to save, and a failed store write must not leave an
 * orphan route behind. The transaction boundary therefore lives here, exactly
 * where the controller's `DB::transaction` sat, and the post-commit log stays
 * in the controller after this returns.
 *
 * The refusals (tenant guard, deleted store 404, already-live 422) stay in the
 * controller: they are status codes and message strings, i.e. HTTP shape, and
 * their order is asserted.
 */
final class StorefrontService
{
    private const NATIONWIDE_DEFAULT_DAYS = 3;

    /**
     * @param  array<string, mixed>  $data  validated by StorefrontEnableRequest
     */
    public function enable(Store $store, array $data): void
    {
        DB::transaction(function () use ($store, $data) {
            $store->update([
                'name' => $data['store_name'],
                'slug' => $data['slug'],
                'has_website' => true,
            ]);

            $this->syncNationwideDelivery($store, $data);
        });
    }

    /**
     * Upserts the "All States" route the storefront checkout charges.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncNationwideDelivery(Store $store, array $data): void
    {
        if (! ($data['is_nationwide'] ?? false)) {
            return;
        }

        $route = $store->deliveryRoutes()->updateOrCreate(
            [
                'state' => StorefrontRepository::NATIONWIDE_STATE,
                'country' => StorefrontRepository::NATIONWIDE_COUNTRY,
            ],
            [
                // Legacy wrote area = null into a NOT NULL column, which fails
                // under MySQL strict mode; '' is the honest value for a route
                // that covers every state.
                'area' => '',
                // The controller's own converter was `(int) round((float) $fee * 100)`,
                // which is koboFromRounded's contract exactly — the naira the
                // wizard typed, blunt float round, no other parse.
                'fee' => Naira::koboFromRounded($data['nationwide_fee'] ?? 0),
                'delivery_days' => (int) ($data['nationwide_days'] ?? self::NATIONWIDE_DEFAULT_DAYS),
                'active' => true,
            ],
        );

        // business_id is not fillable on DeliveryRoute, so legacy's
        // updateOrCreate silently dropped it and left the row unscoped.
        $route->business_id = $store->business_id;
        $route->save();
    }
}
