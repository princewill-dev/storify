<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Payment extends Model
{
    use BelongsToBusiness, HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_ABANDONED = 'abandoned';

    public const TYPE_SUBSCRIPTION = 'subscription';

    public const TYPE_RENEWAL = 'renewal';

    public const TYPE_OTHER = 'other';

    /**
     * The prorated difference on an upgrade, paid mid-period.
     *
     * Its own value rather than `other` because the three things that read this
     * column have to treat it differently: activation swaps the plan and leaves
     * `expires_at` alone (a purchase starts a period, a renewal extends one, a
     * change buys the remainder of the one already running), the checkout prices
     * it from the quoted difference rather than the plan's list price, and the
     * billing list shows it for what it is. The payments table holds it as a
     * MySQL ENUM — see the migration that added it.
     */
    public const TYPE_PLAN_CHANGE = 'plan_change';

    protected $fillable = [
        'payment_code',
        'business_id',
        'user_id',
        'subscription_id',
        'reference',
        'idempotency_key',
        'amount',
        'currency',
        'status',
        'payment_type',
        'payment_gateway',
        'gateway_reference',
        'gateway_response',
        'paid_at',
        'ip_address',
        'failure_reason',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'gateway_response' => 'array',
        'metadata' => 'array',
        'paid_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function (Payment $payment) {
            if (empty($payment->payment_code)) {
                $payment->payment_code = 'pmt_'.Str::upper(Str::random(12));
            }
            if (empty($payment->reference)) {
                $payment->reference = 'ref_'.Str::upper(Str::random(16)).'_'.time();
            }
        });
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 2);
    }
}
