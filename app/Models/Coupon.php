<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Coupon extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'code', 'name', 'subscription_plan_id',
        'discount_type', 'discount_value',
        'max_uses', 'uses_count', 'is_active', 'expires_at',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'max_uses' => 'integer',
        'uses_count' => 'integer',
        'is_active' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function isApplicableTo(?int $planId): bool
    {
        if ($this->subscription_plan_id === null) {
            return true;
        }

        return $this->subscription_plan_id === $planId;
    }

    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }
        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }
        if ($this->max_uses && $this->uses_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    public function isExhausted(): bool
    {
        return $this->max_uses && $this->uses_count >= $this->max_uses;
    }

    public function getDiscountLabelAttribute(): string
    {
        if ($this->discount_type === 'percentage') {
            return number_format((float) $this->discount_value, 0).'%';
        }

        return '₦'.number_format((float) $this->discount_value, 2);
    }

    public function calculateDiscount(float $amount): float
    {
        if ($this->discount_type === 'percentage') {
            return round($amount * ($this->discount_value / 100), 2);
        }

        return min((float) $this->discount_value, $amount);
    }

    public function incrementUsage(): void
    {
        $this->increment('uses_count');
    }
}
