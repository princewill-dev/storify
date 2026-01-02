<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryRoute extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_id',
        'country',
        'state',
        'area',
        'fee',
        'delivery_days',
        'active'
    ];

    protected $casts = [
        'active' => 'boolean',
        'fee' => 'integer',
        'delivery_days' => 'integer',
    ];

    /**
     * Get the store that owns the delivery route.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
