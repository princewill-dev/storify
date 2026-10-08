<?php

namespace App\Services\Management;

use App\Models\BusinessPlugin;
use App\Models\Store;
use App\Models\StorePlugin;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Support\Plugins\PluginRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Writes for the plugins screen.
 *
 * One decision worth naming: connecting a plugin changes what JavaScript runs
 * on every page of a live storefront, so every write records an audit row —
 * including the config values, which are public identifiers that appear in page
 * source anyway. The management `*Service` classes generally do not call
 * {@see ActivityRecorder} (that has been an Admin convention), but "who pasted
 * a pixel ID into this store last Tuesday" is exactly the question this trail
 * should be able to answer.
 *
 * Audits are written inside the same transaction as the mutation, per the
 * recorder's contract, so a failed audit row rolls the write back with it.
 */
final class PluginService
{
    /**
     * Connect or update a plugin, at business level or for one store.
     *
     * @param  array<string, mixed>  $config
     */
    public function connect(
        string $pluginKey,
        array $config,
        bool $isEnabled,
        User $actor,
        ?Store $store = null,
    ): void {
        $plugin = PluginRegistry::get($pluginKey);

        // Re-validated here rather than trusting the request alone: this is the
        // last point before the values are stored, and the same call is what
        // the renderer does before substituting into a script tag.
        $clean = PluginRegistry::validatedConfig($pluginKey, $config);

        DB::transaction(function () use ($pluginKey, $clean, $isEnabled, $actor, $store, $plugin): void {
            $attributes = $store
                ? ['store_id' => $store->id, 'plugin_key' => $pluginKey]
                : ['business_id' => (int) $actor->business_id, 'plugin_key' => $pluginKey];

            $model = $store ? StorePlugin::class : BusinessPlugin::class;

            $existing = $model::query()->where($attributes)->first();
            $before = $existing ? ['is_enabled' => $existing->is_enabled, 'config' => $existing->config] : [];

            $row = $model::query()->updateOrCreate($attributes, [
                'business_id' => (int) $actor->business_id,
                'is_enabled' => $isEnabled,
                'config' => $clean,
            ]);

            ActivityRecorder::record(
                action: 'plugin.connected',
                description: $plugin['name'].' connected'.($store ? " for store {$store->name}" : ''),
                subject: $row,
                old: $before,
                new: ['is_enabled' => $isEnabled, 'config' => $clean],
                metadata: [
                    'plugin_key' => $pluginKey,
                    'scope' => $store ? 'store' : 'business',
                    'store_id' => $store?->id,
                ],
                actor: $actor,
            );
        });
    }

    /**
     * Remove a connection entirely.
     *
     * For a store this deletes the override, so the store falls back to the
     * business default rather than to "off" — those are different states and
     * the UI distinguishes them.
     */
    public function disconnect(string $pluginKey, User $actor, ?Store $store = null): void
    {
        $plugin = PluginRegistry::get($pluginKey);

        DB::transaction(function () use ($pluginKey, $actor, $store, $plugin): void {
            $model = $store ? StorePlugin::class : BusinessPlugin::class;

            $query = $store
                ? $model::query()->where('store_id', $store->id)
                : $model::query()->forBusiness((int) $actor->business_id);

            $existing = $query->forPlugin($pluginKey)->first();

            if ($existing === null) {
                return;
            }

            $before = ['is_enabled' => $existing->is_enabled, 'config' => $existing->config];

            $existing->delete();

            ActivityRecorder::record(
                action: 'plugin.disconnected',
                description: $plugin['name'].' disconnected'.($store ? " for store {$store->name}" : ''),
                subject: $existing,
                old: $before,
                new: [],
                metadata: [
                    'plugin_key' => $pluginKey,
                    'scope' => $store ? 'store' : 'business',
                    'store_id' => $store?->id,
                ],
                actor: $actor,
            );
        });
    }
}
