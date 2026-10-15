<?php

namespace App\Models;

use App\Enums\CustomerStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    use BelongsToBusiness, HasApiTokens, Notifiable;

    public const STATUS_ACTIVE = CustomerStatus::ACTIVE->value;

    public const STATUS_SUSPENDED = CustomerStatus::SUSPENDED->value;

    public const STATUS_DELETED = CustomerStatus::DELETED->value;

    /**
     * The derived states `accountState()` can return, and the whitelist the
     * `account_state` filter validates against.
     *
     * Here rather than in the request so that adding a state is one edit next
     * to the match() that produces it — a state that the filter rejects is a
     * filter that silently returns everything.
     */
    public const ACCOUNT_STATES = ['guest', 'pending', 'verified', 'suspended', 'deleted'];

    protected $fillable = [
        'account_id',
        'business_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'ip_address',
        'email_verified_at',
        'registered_at',
        'deletion_requested_at',
        'status',
        'last_login',
        'location',
        'street_address',
        'city',
        'state',
        'country',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'registered_at' => 'datetime',
        'deletion_requested_at' => 'datetime',
        'last_login' => 'datetime',
    ];

    /**
     * Boot method to auto-generate account_id
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($customer) {
            if (empty($customer->account_id)) {
                $customer->account_id = 'cus_'.strtoupper(Str::random(8));
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
     * The customer's address book — saved entries only.
     *
     * Distinct from `deliveryAddresses()`, which is every row including the
     * per-order snapshots. Anything customer-facing wants this one: the
     * snapshots are one row per order ever placed.
     */
    public function savedAddresses(): HasMany
    {
        return $this->hasMany(DeliveryAddress::class)->where('is_snapshot', false);
    }

    /**
     * The append-only consent trail. Current state is the newest row per type —
     * see App\Services\ConsentService.
     */
    public function consents(): HasMany
    {
        return $this->hasMany(CustomerConsent::class);
    }

    /**
     * Internal notes written by the business. Never customer-facing.
     */
    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
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
        return ! is_null($this->email_verified_at);
    }

    /**
     * Which of the account states this row is in.
     *
     * Derived from timestamps rather than stored, because every one of these
     * is a function of something that already exists and a second enum column
     * could only ever disagree with `status`.
     *
     * `guest` means checkout fabricated this row and nobody has ever chosen a
     * password for it — the legacy shape, since checkout now creates accounts.
     * It reads as `pending` for anyone who has signed in. Both are unverified
     * and both are claimable, so the label is presentational: the state that
     * carries weight is `verified`.
     */
    public function accountState(): string
    {
        return match (true) {
            $this->status === self::STATUS_DELETED => 'deleted',
            $this->status === self::STATUS_SUSPENDED => 'suspended',
            $this->email_verified_at !== null => 'verified',
            $this->registered_at !== null || $this->last_login !== null => 'pending',
            default => 'guest',
        };
    }

    /**
     * The same four states as SQL, for the filters that list them.
     *
     * A deliberate mirror of accountState() above, and the one place in this
     * model where the same thing is defined twice — because one has to run in
     * PHP for a single row and the other in the database for a page of them.
     * They sit next to each other so that changing either is changing both, and
     * the WS-56 coverage test asserts that a row in each state is returned by
     * the filter naming it and by no other.
     *
     * The two statuses that override everything else are checked first, exactly
     * as the match() does.
     */
    public function scopeAccountState($query, string $state)
    {
        $notClosed = fn ($q) => $q->whereNotIn('status', [self::STATUS_DELETED, self::STATUS_SUSPENDED]);

        return match ($state) {
            'deleted' => $query->where('status', self::STATUS_DELETED),
            'suspended' => $query->where('status', self::STATUS_SUSPENDED),
            'verified' => $query->where($notClosed)->whereNotNull('email_verified_at'),
            'pending' => $query->where($notClosed)
                ->whereNull('email_verified_at')
                ->where(fn ($q) => $q->whereNotNull('registered_at')->orWhereNotNull('last_login')),
            'guest' => $query->where($notClosed)
                ->whereNull('email_verified_at')
                ->whereNull('registered_at')
                ->whereNull('last_login'),
            default => $query,
        };
    }

    /**
     * Whether this account may place an order.
     *
     * Verification, not just existence: an order is a financial record
     * attached to a person, and the whole point of collecting an account at
     * checkout is that the person is reachable at the address on it.
     */
    public function canPlaceOrders(): bool
    {
        return $this->status === self::STATUS_ACTIVE && $this->email_verified_at !== null;
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

    public static function statusBadgeData(): array
    {
        return CustomerStatus::badgeData();
    }
}
