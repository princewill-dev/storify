<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'amount',
        'currency',
        'interval',
        'interval_count',
        'is_active',
        'is_default',
        'is_trial',
        'trial_days',
        'features',
        'sort_order',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'is_trial' => 'boolean',
        'features' => 'array',
    ];

    /**
     * Get the subscriptions associated with this plan.
     */
    public function vendorSubscriptions(): HasMany
    {
        return $this->hasMany(VendorSubscription::class);
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

    /**
     * Scope a query to only include trial plans.
     */
    public function scopeTrial(Builder $query): Builder
    {
        return $query->where('is_trial', true);
    }

    /**
     * Determine if the plan is a free trial.
     */
    public function isTrial(): bool
    {
        return $this->is_trial;
    }

    /**
     * Calculate when the trial expires if a vendor starts it now.
     */
    public function getTrialExpiresAt(): ?Carbon
    {
        if (!$this->is_trial || !$this->trial_days) {
            return null;
        }

        return now()->addDays($this->trial_days);
    }
}
