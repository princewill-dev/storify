<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Business extends Model
{
    use HasFactory;

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
        // Whether this business may connect payment methods of its own, or is
        // processed by Storify through the platform's provider account. Set by
        // an admin, never by the business.
        'can_connect_own_gateway',
        // Whether settled money is withdrawn to the business's registered bank
        // automatically, or left in its account at the provider until it asks.
        // An admin's decision, and read by `PlatformPayoutService`.
        'auto_payout',
        // The bank account this business's sales are paid into when Storify
        // processes them. The provider's own view of it — a Paystack subaccount,
        // a Bachs connected account — is built from this row and is never
        // something the business sees or maintains. See the migration for why it
        // is not `store_banks.is_primary`.
        'payout_bank_id',
        // `company` or `individual`, as the owner declared it. A provider that
        // needs the distinction fixes it at the first registration and will not
        // change it afterwards, so the answer is kept rather than derived again
        // on the next processor.
        'payout_entity_type',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'can_connect_own_gateway' => 'boolean',
            'auto_payout' => 'boolean',
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
                    : strtoupper(implode('', array_map(fn ($w) => $w[0] ?? '', array_slice($words, 0, 3))));
                $business->prefix = $prefix ?: 'ST';
            }

            if (empty($business->business_code)) {
                $business->business_code = $business->prefix.'_BIZ_'.Str::upper(Str::random(8));
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

    /**
     * The bank account this business's sales are paid into.
     *
     * A relation rather than a balance, and read through `payout_bank_id`: the
     * row is the business's own, listable and editable in the bank accounts
     * screen, and this only names which of them receives the money.
     */
    public function payoutBank(): BelongsTo
    {
        return $this->belongsTo(StoreBank::class, 'payout_bank_id');
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
        return ! $this->hasActiveSubscription();
    }

    public function getTotalBalance(): int
    {
        return $this->stores()->sum('balance');
    }
}
