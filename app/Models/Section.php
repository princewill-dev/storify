<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Models\BelongsToBusiness;
use App\Enums\SectionStatus;

class Section extends Model
{
    use HasFactory, BelongsToBusiness;

    public const STATUS_ACTIVE = SectionStatus::ACTIVE->value;
    public const STATUS_INACTIVE = SectionStatus::INACTIVE->value;
    public const STATUS_DELETED = SectionStatus::DELETED->value;

    protected $fillable = [
        'section_code', 'warehouse_id', 'business_id', 'name', 'description', 'status',
    ];

    protected $casts = [
        'status' => SectionStatus::class,
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->section_code)) {
                $model->section_code = 'sec_' . Str::lower(Str::random(10));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'section_code';
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
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
        return $this->status === SectionStatus::ACTIVE;
    }
}
