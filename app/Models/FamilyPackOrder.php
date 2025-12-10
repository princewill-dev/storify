<?php

namespace App\Models;

use App\Enums\FamilyPackStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FamilyPackOrder extends Model
{
    protected $fillable = [
        'pack_code',
        'customer_id',
        'store_id',
        'delivery_address_id',
        'delivery_route_id',
        'pack_type',
        'payment_interval',
        'delivery_interval_id',
        'total_cycles',
        'subtotal',
        'estimated_total',
        'status',
        'notes',
        'review_notes',
        'reviewed_at',
        'reviewed_by',
        'first_order_id',
        'last_updated_by',
    ];

    protected $casts = [
        'status' => FamilyPackStatus::class,
        'subtotal' => 'decimal:2',
        'estimated_total' => 'decimal:2',
        'total_cycles' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    /**
     * Helper to check if this is a single purchase
     */
    public function isSingle(): bool
    {
        return $this->pack_type === 'single';
    }

    /**
     * Helper to check if this is a recurring subscription
     */
    public function isRecurring(): bool
    {
        return $this->pack_type === 'recurring';
    }

    /**
     * Get the customer who created this pack
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the store for this pack
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get the delivery address
     */
    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(DeliveryAddress::class);
    }

    /**
     * Get the delivery route
     */
    public function deliveryRoute(): BelongsTo
    {
        return $this->belongsTo(DeliveryRoute::class);
    }

    /**
     * Get the delivery interval
     */
    public function deliveryInterval(): BelongsTo
    {
        return $this->belongsTo(DeliveryInterval::class);
    }

    /**
     * Get admin who reviewed this pack
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Get the first order created from this pack
     */
    public function firstOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'first_order_id');
    }

    /**
     * Get all items in this pack
     */
    public function items(): HasMany
    {
        return $this->hasMany(FamilyPackItem::class);
    }

    /**
     * Get all deliveries for this pack
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(FamilyPackDelivery::class);
    }

    /**
     * Get the subscription for this pack
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(FamilyPackSubscription::class);
    }
}
