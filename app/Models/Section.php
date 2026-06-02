<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use App\Models\Concerns\BelongsToBusiness;

class Section extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'section_code', 'warehouse_id', 'business_id', 'name', 'description', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

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
}
