<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use App\Models\VendorKycApplication;
use App\Enums\VendorStatus;

class Vendor extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable;

    public const STATUS_PENDING = VendorStatus::PENDING->value;
    public const STATUS_ACTIVE = VendorStatus::ACTIVE->value;
    public const STATUS_SUSPENDED = VendorStatus::SUSPENDED->value;
    public const STATUS_DELETED = VendorStatus::DELETED->value;

    protected $fillable = [
        'account_id',
        'name',
        'slug',
        'email',
        'phone',
        'status',
        'password',
        'is_verified',
        'email_verified_at',
        'ip_address',
        'last_login',
        'location',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login' => 'datetime',
        'is_verified' => 'boolean',
        'password' => 'hashed',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function (Vendor $vendor) {
            if (empty($vendor->account_id)) {
                $vendor->account_id = 'vd_' . Str::lower(Str::random(10));
            }
            if (empty($vendor->slug) && !empty($vendor->name)) {
                $vendor->slug = Str::of($vendor->name)->lower()->replace(' ', '_');
            }
            if (empty($vendor->status)) {
                $vendor->status = self::STATUS_ACTIVE;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'account_id';
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function store(): HasOne
    {
        return $this->hasOne(Store::class)->latestOfMany();
    }

    public function transactions(): HasManyThrough
    {
        return $this->hasManyThrough(Transaction::class, Order::class, 'vendor_id', 'order_id', 'id', 'id');
    }

    public function ownershipType(): BelongsTo
    {
        return $this->belongsTo(OwnershipType::class);
    }

    public function businessType(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class);
    }

    public function kycApplication(): HasOne
    {
        return $this->hasOne(VendorKycApplication::class)->latestOfMany();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(VendorSubscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(VendorSubscription::class)
            ->where('status', VendorSubscription::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
            ->latestOfMany();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public static function statusBadgeData(): array
    {
        return VendorStatus::badgeData();
    }

    public function hasApprovedKyc(): bool
    {
        return $this->kycApplication?->status === VendorKycApplication::STATUS_APPROVED;
    }

    public function canCreateMoreStores(): bool
    {
        if (!$this->is_verified) {
            return false;
        }

        $kycStatus = $this->kycApplication?->status;
        if (!in_array($kycStatus, [VendorKycApplication::STATUS_SUBMITTED, VendorKycApplication::STATUS_APPROVED], true)) {
            return false;
        }

        $limit = \App\Models\Setting::value('store_creation_limit') ?? 5;
        return $this->stores()->count() < $limit;
    }

    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription()->exists();
    }

    public function needsSubscription(): bool
    {
        return !$this->hasActiveSubscription();
    }

    public function onboardingStep(): string
    {
        if (!$this->is_verified) {
            return 'verify_email';
        }

        if (!$this->stores()->exists()) {
            return 'create_store';
        }

        if ($this->needsSubscription()) {
            return 'subscribe';
        }

        return 'completed';
    }
}
