<?php

namespace App\Models;

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

    public function vendorSubscriptions(): HasMany
    {
        return $this->hasMany(VendorSubscription::class);
    }

    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 2);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeTrial($query)
    {
        return $query->where('is_trial', true);
    }

    public function isTrial(): bool
    {
        return $this->is_trial;
    }

    public function getTrialExpiresAt(): ?\Carbon\Carbon
    {
        if (!$this->is_trial || !$this->trial_days) {
            return null;
        }
        return now()->addDays($this->trial_days);
    }
}
