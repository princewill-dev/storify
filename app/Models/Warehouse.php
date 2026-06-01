<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;
use App\Models\Concerns\BelongsToBusiness;

class Warehouse extends Model
{
    use HasFactory, BelongsToBusiness;

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
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
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
}
