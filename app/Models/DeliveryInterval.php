<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeliveryInterval extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'days_count',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'days_count' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Scope to get only active intervals
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }
}
