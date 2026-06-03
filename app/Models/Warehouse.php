<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use App\Models\Concerns\BelongsToBusiness;
use App\Enums\WarehouseStatus;

class Warehouse extends Model
{
    use HasFactory, BelongsToBusiness;

    public const STATUS_ACTIVE = WarehouseStatus::ACTIVE->value;
    public const STATUS_INACTIVE = WarehouseStatus::INACTIVE->value;
    public const STATUS_DELETED = WarehouseStatus::DELETED->value;

    protected $fillable = [
        'warehouse_code',
        'user_id',
        'business_id',
        'name',
        'address',
        'city',
        'state',
        'country',
        'contact_person',
        'contact_phone',
        'description',
        'status',
    ];

    protected $casts = [
        'status' => WarehouseStatus::class,
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function (Warehouse $warehouse) {
            if (empty($warehouse->warehouse_code)) {
                $warehouse->warehouse_code = 'whs_' . Str::lower(Str::random(10));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'warehouse_code';
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function stockLocations(): MorphMany
    {
        return $this->morphMany(StockLocation::class, 'locationable');
    }

    public function assignedStaff()
    {
        return $this->morphToMany(User::class, 'assignmentable', 'staff_assignments');
    }

    public function scopeNotDeleted($query)
    {
        return $query->where('status', '!=', self::STATUS_DELETED);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === WarehouseStatus::ACTIVE;
    }
}
