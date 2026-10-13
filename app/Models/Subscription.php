<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Subscription extends Model
{
    use BelongsToBusiness, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'business_id',
        'user_id',
        'subscription_plan_id',
        'pending_plan_id',
        'pending_charge_kobo',
        'pending_requested_at',
        'scheduled_plan_id',
        'scheduled_at',
        'subscription_code',
        'status',
        'starts_at',
        'expires_at',
        'cancelled_at',
        'metadata',
        'trial_reminder_day5_sent_at',
        'trial_reminder_day6_sent_at',
        'trial_reminder_day7_sent_at',
        'trial_expired_sent_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'pending_requested_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'pending_charge_kobo' => 'integer',
        'metadata' => 'array',
        'trial_reminder_day5_sent_at' => 'datetime',
        'trial_reminder_day6_sent_at' => 'datetime',
        'trial_reminder_day7_sent_at' => 'datetime',
        'trial_expired_sent_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function (Subscription $subscription) {
            if (empty($subscription->subscription_code)) {
                $subscription->subscription_code = 'sub_'.Str::upper(Str::random(12));
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    /**
     * The plan an upgrade is waiting to be paid for. Null when none is.
     *
     * Not the plan in force — that is {@see self::subscriptionPlan()}. The two
     * differ exactly while a prorated charge is in flight, which is the state
     * that has to be read carefully: entitlement comes from the plan in force
     * until the money lands, so a business cannot spend an upgrade it has not
     * paid for by starting a checkout.
     */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'pending_plan_id');
    }

    /**
     * The plan a downgrade takes effect on when the paid period ends.
     */
    public function scheduledPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'scheduled_plan_id');
    }

    public function hasPendingChange(): bool
    {
        return $this->pending_plan_id !== null;
    }

    public function hasScheduledChange(): bool
    {
        return $this->scheduled_plan_id !== null;
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->expires_at
            && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED
            || ($this->expires_at && $this->expires_at->isPast());
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where(function ($q) {
            $q->where('status', self::STATUS_EXPIRED)
                ->orWhere('expires_at', '<=', now());
        });
    }
}
