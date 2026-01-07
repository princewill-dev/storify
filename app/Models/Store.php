<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\StoreStatus;

class Store extends Model
{
    use HasFactory;

    public const STATUS_PENDING = StoreStatus::PENDING->value;
    public const STATUS_ACTIVE = StoreStatus::ACTIVE->value;
    public const STATUS_SUSPENDED = StoreStatus::SUSPENDED->value;
    public const STATUS_DELETED = StoreStatus::DELETED->value;

    protected $fillable = [
        'store_id',
        'vendor_id',
        'name',
        'slug',
        'description',
        'logo_path',
        'support_email',
        'support_phone',
        'address',
        'instagram_url',
        'facebook_url',
        'twitter_url',
        'tiktok_url',
        'ownership_type_id',
        'business_type_id',
        'status',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->store_id)) {
                $model->store_id = 'st_' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            }
            if (empty($model->slug) && !empty($model->name)) {
                $base = strtolower(str_replace(' ', '_', $model->name));
                $slug = $base;
                $tries = 0;
                while (Store::where('slug', $slug)->exists()) {
                    $suffix = '-' . str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT);
                    $slug = $base . $suffix;
                    if (++$tries > 10) { break; }
                }
                $model->slug = $slug;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'store_id';
    }

    /**
     * Scope a query to only include active stores.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function ownershipType(): BelongsTo
    {
        return $this->belongsTo(OwnershipType::class);
    }

    public function businessType(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class);
    }

    public function banks(): HasMany
    {
        return $this->hasMany(StoreBank::class);
    }

    public function primaryBank()
    {
        return $this->hasOne(StoreBank::class)->where('is_primary', true);
    }

    public function deliveryRoutes(): HasMany
    {
        return $this->hasMany(DeliveryRoute::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public static function statusBadgeData(): array
    {
        return StoreStatus::badgeData();
    }
}
