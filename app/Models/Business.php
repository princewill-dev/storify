<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Business extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'prefix',
        'business_code',
        'description',
        'ownership_type_id',
        'business_type_id',
        'business_model',
        'currency',
        'physical_store_count',
        'store_slug',
        'business_location',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Business $business) {
            if (empty($business->prefix)) {
                $words = explode(' ', preg_replace('/[^a-zA-Z\s]/', '', $business->name));
                $words = array_values(array_filter($words));
                $prefix = count($words) === 1
                    ? strtoupper(substr($words[0], 0, 2))
                    : strtoupper(implode('', array_map(fn($w) => $w[0] ?? '', array_slice($words, 0, 3))));
                $business->prefix = $prefix ?: 'ST';
            }

            if (empty($business->business_code)) {
                $business->business_code = $business->prefix . '_BIZ_' . Str::upper(Str::random(8));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'business_code';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->latestOfMany();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function gateways(): HasMany
    {
        return $this->hasMany(BusinessGateway::class);
    }

    public function kycApplications(): HasMany
    {
        return $this->hasMany(KycApplication::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription()->exists();
    }

    public function needsSubscription(): bool
    {
        return !$this->hasActiveSubscription();
    }

    public function getTotalBalance(): int
    {
        return $this->stores()->sum('balance');
    }
}
