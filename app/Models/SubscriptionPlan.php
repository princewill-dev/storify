<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_code',
        'name',
        'description',
        'amount',
        'currency',
        'interval',
        'interval_count',
        'is_active',
        'is_default',
        'features',
        'entitlements',
        'tier',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'features' => 'array',
        // What this plan grants, keyed by
        // {@see \App\Support\Plans\EntitlementRegistry}. Null is a meaningful
        // value for a limit — it means unlimited — so this is read with
        // `is_array()` and never with `??` further down.
        'entitlements' => 'array',
        'tier' => 'integer',
    ];

    protected static function booted()
    {
        static::creating(function ($plan) {
            if (empty($plan->plan_code)) {
                $plan->plan_code = Str::random(24);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'plan_code';
    }

    /**
     * Get the subscriptions associated with this plan.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Get the formatted amount.
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 2);
    }

    /**
     * Scope a query to only include active plans.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include default plans.
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
