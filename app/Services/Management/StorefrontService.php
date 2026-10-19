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
     * Takes the storefront offline, keeping everything that would be awkward or
     * impossible to win back.
     *
     * The slug stays and is never cleared. Re-enabling must not have to
     * re-claim the address, and while this row still holds it no other store can
     * take it — `Rule::unique('stores','slug')` sees it, and so does the store
     * create form's availability check.
     *
     * The nation-wide delivery route stays for the same reason:
     * `syncNationwideDelivery()` upserts it, so re-enabling should not have to
     * rebuild it out of a second form. Nothing about the route is served while
     * the storefront is off, because the whole storefront refuses at
     * ResolvesStorefrontContext.
     *
     * One column changes and nothing else can be left half-applied, so unlike
     * `enable()` this is not wrapped in a transaction.
     */
    public function disable(Store $store): void
    {
        $store->update(['has_website' => false]);
    }

    /**
     * Changes a live storefront's public address.
     *
     * One column, so unlike `enable()` there is nothing that could be left
     * half-applied and no transaction. `has_website` and the nation-wide
     * delivery route are untouched: this is not the enable path, and it must
     * not rewrite what the storefront already runs on.
     */
    public function updateSlug(Store $store, string $slug): void
    {
        $store->update(['slug' => $slug]);
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
