<?php

namespace App\Services\Plugins;

use App\Models\BusinessPlugin;
use App\Models\Store;
use App\Models\StorePlugin;
use App\Support\Plugins\PluginRegistry;
use App\Support\Plugins\PluginTagRenderer;
use Illuminate\Support\Collection;

/**
 * Resolves which plugins are live for a store.
 *
 * The precedence rule, in one place because both the public storefront endpoint
 * and the management screen must agree on it:
 *
 *   a `store_plugins` row for that store  →  wins, enabled or not
 *   otherwise the `business_plugins` row  →  used
 *   neither                               →  not connected
 *
 * The store row's *existence* is what makes it an override, not its
 * `is_enabled` flag. A store disabling something the business enabled is a real
 * case, and treating "disabled" and "absent" alike would silently re-enable it.
 */
final class PluginResolver
{
    /**
     * Enabled plugins for a store, as `[pluginKey => config]` — the exact shape
     * {@see PluginTagRenderer::render()} consumes.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forStore(Store $store): array
    {
        return $this->resolve(
            $this->businessRows((int) $store->business_id),
            $this->storeRows((int) $store->id),
        );
    }

    /**
     * Enabled plugins for a business, ignoring any store override.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forBusiness(int $businessId): array
    {
        return $this->resolve($this->businessRows($businessId), collect());
    }

    /**
     * Every plugin's state for the management screen, including the ones that
     * are off — the UI has to render a card for each.
     *
     * `source` is what the screen badges: `store` when a store row decides it,
     * `business` when it inherits, `none` when nothing is set.
     *
     * @return array<int, array<string, mixed>>
     */
    public function states(int $businessId, ?Store $store = null): array
    {
        $business = $this->businessRows($businessId);
        $storeRows = $store ? $this->storeRows((int) $store->id) : collect();

        $states = [];

        foreach (PluginRegistry::keys() as $key) {
            $businessRow = $business->get($key);
            $storeRow = $storeRows->get($key);

            $effective = $storeRow ?? $businessRow;

            $states[] = [
                'plugin_key' => $key,
                'is_enabled' => (bool) ($effective?->is_enabled ?? false),
                'config' => $effective?->config ?? [],
                'source' => $storeRow ? 'store' : ($businessRow ? 'business' : 'none'),
                'business_connected' => $businessRow !== null && (bool) $businessRow->is_enabled,
                'store_overrides' => $storeRow !== null,
            ];
        }

        return $states;
    }

    /**
     * @param  Collection<string, BusinessPlugin>  $business
     * @param  Collection<string, StorePlugin>  $storeRows
     * @return array<string, array<string, mixed>>
     */
    private function resolve(Collection $business, Collection $storeRows): array
    {
        $enabled = [];

        foreach (PluginRegistry::keys() as $key) {
            $row = $storeRows->get($key) ?? $business->get($key);

            if ($row === null || ! $row->is_enabled) {
                continue;
            }

            $enabled[$key] = (array) ($row->config ?? []);
        }

        return $enabled;
    }

    /**
     * @return Collection<string, BusinessPlugin>
     */
    private function businessRows(int $businessId): Collection
    {
        return BusinessPlugin::query()
            ->forBusiness($businessId)
            ->get()
            ->keyBy('plugin_key');
    }

    /**
     * @return Collection<string, StorePlugin>
     */
    private function storeRows(int $storeId): Collection
    {
        return StorePlugin::query()
            ->where('store_id', $storeId)
            ->get()
            ->keyBy('plugin_key');
    }
}
