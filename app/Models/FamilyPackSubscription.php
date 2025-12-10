<?php

namespace App\Models;

use App\Enums\PaymentInterval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FamilyPackSubscription extends Model
{
    protected $fillable = [
        'family_pack_order_id',
        'customer_id',
        'payment_interval',
        'interval_amount',
        'total_cycles',
        'current_cycle',
        'remaining_cycles',
        'next_payment_date',
        'last_payment_date',
        'total_paid',
        'is_active',
        'is_paused',
        'paused_at',
        'paused_until',
        'cancelled_at',
        'cancelled_by',
    ];

    protected $casts = [
        'payment_interval' => PaymentInterval::class,
        'interval_amount' => 'decimal:2',
        'total_cycles' => 'integer',
        'current_cycle' => 'integer',
        'remaining_cycles' => 'integer',
        'total_paid' => 'decimal:2',
        'next_payment_date' => 'date',
        'last_payment_date' => 'date',
        'paused_until' => 'date',
        'is_active' => 'boolean',
        'is_paused' => 'boolean',
        'paused_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Get the family pack order
     */
    public function familyPackOrder(): BelongsTo
    {
        return $this->belongsTo(FamilyPackOrder::class);
    }

    /**
     * Get the customer
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get who cancelled the subscription
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Check if subscription is currently paused
     */
    public function isPaused(): bool
    {
        return $this->is_paused;
    }

    /**
     * Check if subscription is cancelled
     */
    public function isCancelled(): bool
    {
        return !is_null($this->cancelled_at);
    }

    /**
     * Scope to get active subscriptions
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->whereNull('cancelled_at');
    }
}
