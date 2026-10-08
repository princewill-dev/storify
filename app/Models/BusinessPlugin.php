<?php

namespace App\Models;

use App\Support\Plugins\PluginRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A business-wide plugin default.
 *
 * `plugin_key` points into {@see PluginRegistry} rather than a table — the
 * plugin catalogue is code, so adding a service is a registry entry and never
 * a migration. Nothing here knows what a field means; the registry does.
 *
 * @property int $business_id
 * @property string $plugin_key
 * @property bool $is_enabled
 * @property array<string, mixed>|null $config
 */
class BusinessPlugin extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'plugin_key',
        'is_enabled',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'config' => 'array',
        ];
    }

    /**
     * @param  Builder<BusinessPlugin>  $query
     */
    public function scopeForPlugin(Builder $query, string $pluginKey): void
    {
        $query->where('plugin_key', $pluginKey);
    }
}
