<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use App\Enums\CustomerStatus;
use App\Enums\LiveFirstStatus;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\Concerns\BelongsToBusiness;

class Customer extends Authenticatable
{
    use Notifiable, BelongsToBusiness;

    public const STATUS_ACTIVE = CustomerStatus::ACTIVE->value;
    public const STATUS_SUSPENDED = CustomerStatus::SUSPENDED->value;
    public const STATUS_DELETED = CustomerStatus::DELETED->value;

    protected $fillable = [
        'account_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'ip_address',
        'email_verified_at',
        'status',
        'last_login',
        'location',
        'live_first_status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login' => 'datetime',
        'live_first_status' => LiveFirstStatus::class,
    ];

    /**
     * Boot method to auto-generate account_id
     */
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($customer) {
            if (empty($customer->account_id)) {
                $customer->account_id = 'cus_' . strtoupper(Str::random(8));
            }

            if (empty($customer->status)) {
                $customer->status = self::STATUS_ACTIVE;
            }
        });
    }

    /**
     * Get the customer's orders
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the customer's delivery addresses
     */
    public function deliveryAddresses(): HasMany
    {
        return $this->hasMany(DeliveryAddress::class);
    }

    /**
     * Get the customer's default delivery address
     */
    public function defaultDeliveryAddress()
    {
        return $this->hasOne(DeliveryAddress::class)->where('is_default', true);
    }

    /**
     * Get the customer's transactions through orders
     */
    public function transactions()
    {
        return $this->hasManyThrough(Transaction::class, Order::class);
    }

    /**
     * Get full name attribute
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Get name attribute (alias for full_name for compatibility)
     */
    public function getNameAttribute(): string
    {
        return $this->full_name;
    }

    /**
     * Scope to filter active customers
     */
    public function scopeActive($query)
    {
        return $query->whereNotNull('email_verified_at');
    }

    /**
     * Check if customer has verified email
     */
    public function hasVerifiedEmail(): bool
    {
        return !is_null($this->email_verified_at);
    }

    /**
     * Mark email as verified
     */
    public function markEmailAsVerified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    public function getRouteKeyName(): string
    {
        return 'account_id';
    }

    /**
     * Get the customer's Live First application
     */
    public function liveFirstApplication(): HasOne
    {
        return $this->hasOne(LiveFirstApplication::class, 'user_id');
    }

    public static function statusBadgeData(): array
    {
        return CustomerStatus::badgeData();
    }
}
