<?php

namespace App\Models;

use App\Enums\LiveFirstStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasRoles, Notifiable;

    public const ROLE_SUPERADMIN = 'superadmin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_BUSINESS_OWNER = 'business_owner';
    public const ROLE_USER = 'user';

    protected $fillable = [
        'uuid', 'account_code', 'name', 'email', 'phone', 'photo_path',
        'role', 'status', 'is_verified', 'last_login_at', 'location',
        'ip_address', 'password', 'business_id',
        'invitation_token', 'invited_at', 'accepted_at', 'force_password_change',
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_verified' => 'boolean',
            'last_login_at' => 'datetime',
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'force_password_change' => 'boolean',
            'live_first_status' => LiveFirstStatus::class,
        ];
    }

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }

            if (empty($model->account_code)) {
                $prefix = $model->business->prefix ?? 'PL';
                $model->account_code = $prefix . '_ACT_' . Str::upper(Str::random(8));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'account_code';
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function liveFirstApplication(): HasOne
    {
        return $this->hasOne(LiveFirstApplication::class);
    }

    /**
     * Get stores the user owns (for business owners).
     * For staff, use assignedStores().
     */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    /**
     * Get all stores accessible to this user — owned stores for business owners,
     * assigned stores for staff. Use this in controllers that serve both roles.
     */
    public function accessibleStores(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\MorphToMany|\Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->isStaff() ? $this->assignedStores() : $this->stores();
    }

    /**
     * Get all warehouses accessible to this user.
     */
    public function accessibleWarehouses(): \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Eloquent\Relations\MorphToMany|\Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->isStaff() ? $this->assignedWarehouses() : $this->warehouses();
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function kycApplication()
    {
        return $this->hasOne(KycApplication::class)->latestOfMany();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function assignedStores(): MorphToMany
    {
        return $this->morphedByMany(Store::class, 'assignmentable', 'staff_assignments');
    }

    public function assignedWarehouses(): MorphToMany
    {
        return $this->morphedByMany(Warehouse::class, 'assignmentable', 'staff_assignments');
    }

    public function posSessions(): HasMany
    {
        return $this->hasMany(PosSession::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(StaffDocument::class);
    }

    public function getTotalBalanceInNaira(): float
    {
        return $this->business?->getTotalBalance() / 100 ?? 0;
    }

    public function getFormattedTotalBalance(): string
    {
        return '₦' . number_format($this->getTotalBalanceInNaira(), 2);
    }

    public function getOnboardingProgress(): array
    {
        return [
            'step' => 'complete',
            'completed' => true,
            'next_route' => route('management.dashboard'),
            'progress_percentage' => 100,
        ];
    }

    public function isBusinessOwner(): bool
    {
        return $this->role === self::ROLE_BUSINESS_OWNER;
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_SUPERADMIN]);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->business_id === null && $this->role === self::ROLE_SUPERADMIN;
    }

    public function scopeNotDeleted($query)
    {
        return $query->where('status', '!=', 'deleted');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function photoUrl(): string
    {
        if ($this->photo_path) {
            return asset('storage/' . $this->photo_path);
        }

        $hash = md5($this->email ?: $this->name);
        return 'https://www.gravatar.com/avatar/' . $hash . '?d=mp&s=200';
    }

    /**
     * Used by Spatie/laravel-permission for team-based role scoping.
     */
    protected function getDefaultTeamId(): ?int
    {
        return $this->business_id;
    }
}
