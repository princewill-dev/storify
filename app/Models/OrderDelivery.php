<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDelivery extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'order_id',
        'business_id',
        'status',
        'delivery_route_id',
        'driver_name',
        'driver_phone',
        'tracking_number',
        'current_location',
        'estimated_delivery_at',
        'actual_delivery_at',
        'delivery_notes',
        'recipient_name',
        'recipient_signature',
        'return_reason',
        'created_by',
    ];

    protected $casts = [
        'estimated_delivery_at' => 'datetime',
        'actual_delivery_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function deliveryRoute(): BelongsTo
    {
        return $this->belongsTo(DeliveryRoute::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
