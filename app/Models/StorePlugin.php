<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plugin override for one store.
 *
 * Wins over {@see BusinessPlugin} for the same key. The row's existence is what
 * makes it an override — a row with `is_enabled = false` is how a store turns
 * off something the business enabled, so resolvers must not treat "disabled"
 * and "absent" the same way.
 *
 * @property int $business_id
 * @property int $store_id
 * @property string $plugin_key
 * @property bool $is_enabled
 * @property array<string, mixed>|null $config
 */
class StorePlugin extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'store_id',
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

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @param  Builder<StorePlugin>  $query
     */
    public function scopeForPlugin(Builder $query, string $pluginKey): void
    {
        $query->where('plugin_key', $pluginKey);
    }
}
