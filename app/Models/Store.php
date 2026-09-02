<?php

namespace App\Models;

use App\Enums\StoreStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Store extends Model
{
    use BelongsToBusiness, HasFactory;

    public const STATUS_PENDING = StoreStatus::PENDING->value;

    public const STATUS_ACTIVE = StoreStatus::ACTIVE->value;

    public const STATUS_SUSPENDED = StoreStatus::SUSPENDED->value;

    public const STATUS_DELETED = StoreStatus::DELETED->value;

    protected $fillable = [
        'store_id',
        'user_id',
        'business_id',
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
        'currency_id',
        'status',
        'balance',
        'views',
        'payment_mode',
        'pos_enabled',
        'physical_address',
        'store_type',
        'has_website',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->store_id)) {
                $model->store_id = 'st_'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
            }
            if (empty($model->slug) && ! empty($model->name)) {
                $base = strtolower(str_replace(' ', '_', $model->name));
                $slug = $base;
                $tries = 0;
                while (Store::where('slug', $slug)->exists()) {
                    $suffix = '-'.str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
                    $slug = $base.$suffix;
                    if (++$tries > 10) {
                        break;
                    }
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

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
        return $this->hasMany(StoreBank::class, 'business_id', 'business_id');
    }

    public function assignedBanks(): BelongsToMany
    {
        return $this->belongsToMany(StoreBank::class, 'store_bank', 'store_id', 'store_bank_id')
            ->withTimestamps()->withPivot('is_active');
    }

    public function paymentMethods(): BelongsToMany
    {
        return $this->belongsToMany(PaymentMethod::class, 'store_payment_method', 'store_id', 'payment_method_id')
            ->withPivot('is_active')->withTimestamps();
    }

    public function primaryBank()
    {
        return $this->hasOne(StoreBank::class, 'business_id', 'business_id')->where('is_primary', true);
    }

    public function deliveryRoutes(): HasMany
    {
        return $this->hasMany(DeliveryRoute::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function stockLocations(): MorphMany
    {
        return $this->morphMany(StockLocation::class, 'locationable');
    }

    public function posSessions(): HasMany
    {
        return $this->hasMany(PosSession::class);
    }

    public function assignedStaff(): MorphToMany
    {
        return $this->morphToMany(User::class, 'assignmentable', 'staff_assignments');
    }

    public function activePosSession()
    {
        return $this->hasOne(PosSession::class)->where('status', PosSession::STATUS_OPEN)->latestOfMany();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function serviceCharges(): HasMany
    {
        return $this->hasMany(ServiceCharge::class);
    }

    /**
     * Atomically credit the store balance
     *
     * @param  int  $amountInKobo  Amount to credit in kobo
     *
     * @throws \Exception
     */
    public function creditBalance(int $amountInKobo): bool
    {
        if ($amountInKobo <= 0) {
            throw new \Exception('Credit amount must be positive');
        }

        return \DB::transaction(function () use ($amountInKobo) {
            $store = Store::query()->lockForUpdate()->findOrFail($this->id);
            $store->increment('balance', $amountInKobo);
            $this->refresh();

            return true;
        });
    }

    /**
     * Atomically debit the store balance
     *
     * @param  int  $amountInKobo  Amount to debit in kobo
     *
     * @throws \Exception
     */
    public function debitBalance(int $amountInKobo): bool
    {
        if ($amountInKobo <= 0) {
            throw new \Exception('Debit amount must be positive');
        }

        return \DB::transaction(function () use ($amountInKobo) {
            // Lock the row for update
            $store = Store::query()->lockForUpdate()->findOrFail($this->id);

            // Check sufficient funds
            if ($store->balance < $amountInKobo) {
                throw new \Exception('Insufficient balance');
            }

            // Use decrement for atomic database operation
            $store->decrement('balance', $amountInKobo);
            $this->refresh();

            return true;
        });
    }

    /**
     * Get balance in Naira (conversion from kobo)
     */
    public function getBalanceInNaira(): float
    {
        return $this->balance / 100;
    }

    /**
     * Get formatted balance for display
     */
    public function getFormattedBalance(): string
    {
        return '₦'.number_format($this->getBalanceInNaira(), 2);
    }

    public function logoUrl(): ?string
    {
        if ($this->logo_path) {
            return asset('storage/'.$this->logo_path);
        }

        return null;
    }

    public static function statusBadgeData(): array
    {
        return StoreStatus::badgeData();
    }
}
